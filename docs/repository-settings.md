# Repository settings

Each repository has its own settings. To open them, click the gear icon on the repository's row, or click its name.

The header sums up the saved settings: the repository, the model and effort, and the pace. A side nav jumps to each section. A dot next to **Permissions** means the tool list has entries from an old default. **Edited** next to **Instructions** means the prompt is no longer the default.

The footer stays at the bottom of the screen. It lists which sections have unsaved changes. **Discard** puts back the saved values, and **Save changes** saves them.

## Repository

### Local checkout

A git clone on this machine. `~/` is expanded. **Browse…** opens your system's folder picker. **Detect** reads the GitHub remote from the folder.

Foreman never works in this folder directly. Each run gets its own worktree under `storage/foreman/worktrees/`.

### GitHub repository

The repository on GitHub, as `owner/name`. **Detect** fills it in.

### Name in Foreman

The name shown on the dashboard. **Detect** fills it in from the repository name if it's empty.

### Branch PRs target

The base branch. Default: `main`. Each run's branch starts from `origin/<base>`, and the PR targets it.

## Model & limits

The model, effort and profile come first. Below them, a **Budget** card holds the spend limits, and a **Pace and stop conditions** card holds the rest.

### Model

Haiku, Sonnet, Opus or Fable, picked from a row of buttons. Default: Sonnet. Passed to `claude --model`.

You can also change the model, and the effort below, from the repository's row on the dashboard. Click the model name under the repository's name, pick new values and press Save. The change applies to new runs only.

### Effort

How hard Claude thinks. Default, Low, Medium, High, XHigh or Max. Passed to `claude --effort`.

**Default** leaves `--effort` out, so Claude uses its own default. Every model accepts every level. Higher levels let Claude think longer, which usually costs more tokens.

Like the model, effort applies to new runs. A run that is already queued keeps the model and effort it was created with.

### Claude profile

Which Claude Code login to run under. The menu lists the profiles in `CLAUDE_PROFILES`. With no profiles set up, it shows **Default (~/.claude)**. See [Usage limits and profiles](usage-limits.md#claude-profiles).

### Per day

The daily limit, in USD. Foreman stops starting runs for this repository once today's spend reaches this amount. Empty means no limit. When neither budget field is set, the card says so. When the limit is reached, the row shows "Daily limit … reached, paused until tomorrow". Runs already in progress finish.

Spend is the cost Claude Code reports for each run. On a subscription it's an estimate, but it's still useful for comparing runs. There's also a limit across all repositories. See [Global settings](global-settings.md#spend).

### Per run

The most a single run may spend, in USD. Empty means no limit. Passed to `claude --max-budget-usd`.

The cap counts sub-agents too. It may also overshoot by one response, because Claude checks it between responses. A run that hits the cap stops and usually ends as Failed.

### Issues at a time

How many runs this repository may have active at once, from 1 to 8. Default: 1. You can also change it with **−** / **+** on the dashboard.

All repositories share three workers. So more than three runs at once only helps if you start more workers. See [Troubleshooting](troubleshooting.md#runs-stay-on-waiting).

### Check for issues every

How often, in minutes, Foreman looks for ready issues, from 1 to 1440. Default: 10. Checks only call `gh`, so they cost no tokens.

### Max turns per issue

The most turns Claude may take each time Foreman starts the agent, from 1 to 500. Default: 40. Passed to `claude --max-turns`. A *turn* is one round of Claude replying or using a tool. A run that runs out of turns usually ends as Failed.

The limit is not shared across a run. The [review pass](guardrails.md#risk-tiers), the `/security-review` pass and a [scope correction](guardrails.md#scope-judge) each start the agent again with the full limit. Only **Limit per run** is shared.

### Max time per issue

A wall-clock limit each time Foreman starts the agent, from 1 to 600. Default: 45. When it's reached, Foreman stops Claude and the run fails with "Hit the 45-minute limit." The setup script doesn't count toward it. Like **Max turns per issue**, each pass of a run gets the full time again.

## Labels

Foreman uses three GitHub labels. It creates them when you save. If a label already exists, Foreman updates its colour and description.

| Setting | Default | Meaning |
| --- | --- | --- |
| Ready for the agent | `ready-for-agent` | Add this to an issue to queue it. |
| Being worked on | `agent-working` | Replaces the ready label while a run owns the issue. |
| Needs a human | `needs-human` | Added when the agent or Foreman needs you. |

The working and human labels must differ from the ready label. If the labels can't be created, the settings still save, and a warning shows at the top of the form.

## Scripts

The line under the heading names the stacks Foreman found in the checkout, such as PHP or TypeScript. See [Stack rules](stack-rules.md#stack-rules).

### Setup script

Bash that runs in each new worktree before the agent starts. It runs with `-e`, so it stops at the first failing line, and it has a 15-minute timeout. If it fails, the run fails with the script's output.

The default suits a Laravel app with npm:

```bash
cp .env.example .env
composer install --no-interaction --quiet
php artisan key:generate --quiet
npm ci --silent
npm run build --silent
```

The code icon (`< >`) next to the label copies a prompt for Claude Code. Paste it into `claude` in the project, and Claude works out a script that fits. The prompt uses what's in the form now, including unsaved edits.

The setup script runs as you, with no sandbox. See [Known gaps](security.md#known-gaps). It doesn't get the variables from Foreman's own `.env`, so the worktree's `.env` decides its settings. See [Worktree processes don't get these settings](global-settings.md#worktree-processes-dont-get-these-settings).

### Verify script

Bash that decides when the agent is done. The agent can't stop until this script exits 0 and the [test-integrity check](guardrails.md#test-integrity) passes. It runs in the worktree, with `-e`, so it stops at the first failing line. It has a 25-minute timeout.

Leave it empty to use the checks Foreman finds for the stacks in the worktree. For a Laravel app with Pint, PHPStan and Pest, and a base branch of `main`, that is:

```bash
(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/pint --test --)
vendor/bin/phpstan analyse
vendor/bin/pest
```

The first line runs Pint only on the files the branch changed, so formatting problems already on the base branch don't fail the run. PHPStan runs only when the repo has a `phpstan.neon`, `phpstan.neon.dist` or `phpstan.dist.neon`. Without one, PHPStan has no paths to analyse and always fails. See [What the default checks cover](stack-rules.md#what-the-default-checks-cover).

When you add a repository and click **Detect** or **Browse…**, or open the settings of one that has no script yet, the form fills in this default so you can see and edit it. **Use detected checks** puts this default back in the field. If you save it unchanged, Foreman stores nothing, so the default keeps following the base branch and the stacks it finds. The [stack rules](stack-rules.md#stack-rules) table lists the default for each language.

Use check-only commands, such as `pint --test`, so the script never changes files. Match what your CI runs.

The code icon (`< >`) next to the label copies a prompt for Claude Code, like the one for the setup script. Paste it into `claude` in the project, and Claude works out a script that matches your CI.

If the checks keep failing, the agent is sent back three times and then allowed to stop. See [Troubleshooting](troubleshooting.md#stop-hook-gave-up).

The verify script runs as you, with no sandbox. Changes apply to new runs.

## Permissions

### Allowed tools

The tools Claude may use, passed to `claude --allowedTools`. Anything not listed is refused. The default lets the agent:

- read and edit files, search, and use skills and sub-agents
- run the git commands that inspect and commit: `git status`, `git diff`, `git log`, `git show`, `git add` and `git commit`
- run `composer`, `php`, `./vendor/bin/*`, `npm`, `npx`, `pnpm` and `yarn`
- run Rust tools (`cargo`, `rustfmt`) and Python tools (`python`, `python3`, `pytest`, `ruff`, `mypy`, `pyright`, `uv` and `poetry`)

The list has no `gh` and no `git push`. Foreman pushes the branch and opens the PR itself, and the agent's environment can't push anyway. See [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent). Runs use Claude's `dontAsk` permission mode, so anything not on the list is refused without asking. If one command in a chained Bash call isn't on the list, the whole call is refused. The default prompt tells the agent to split the call and retry without that command.

The form shows the list as chips, grouped into Files, Agents, Shell and Other. A shell chip shows what's inside `Bash(…)`, so `Bash(npm:*)` shows as `npm:*`. Hover a chip to see the full entry.

- **+ Add tool** adds one entry, such as `Bash(make:*)`.
- **Edit as text** shows the list as comma-separated text, for bigger changes.
- **Restore default** puts the default list back.

Fix-up rounds use the same list. Foreman reads the CI results and puts them in the prompt, so the agent needs no `gh` tools. See [PR watching](pr-watching.md).

#### Old tool lists

A repository saved before Foreman took over pushing may still list `Bash(git:*)`, `Bash(gh issue:*)` or `Bash(gh pr create:*)`. Foreman doesn't touch saved lists. Instead, the settings page shows a notice above the list, and the old entries are struck through. **Remove them** takes out just those entries and keeps the rest of the list. Click **Save changes** to keep the result.

The `gh` entries do nothing now. The guard hook blocks every `gh` command and `git push`, whatever the list says. `Bash(git:*)` still matters, because it lets the agent run git commands beyond inspecting and committing. Keep it only if you need that.

A saved list also misses entries added to the default later, such as `Bash(php:*)`. Without it, commands like `php -v` or `php -l` are refused. Add it with **+ Add tool**, or click **Restore default**. Then click **Save changes**.

### Locked paths

Files the agent may not edit. Optional. The form shows one pattern per row. Click **×** to remove a pattern, or type one in the last row and click **Add**. Patterns start at the repository root. `*` matches within one folder, `**` matches across folders, and `?` matches one character. A pattern ending in `/` locks the whole folder. For example:

```text
database/migrations/**
tests/Feature/BillingTest.php
```

Foreman blocks the edit and tells the agent which path is locked. This is on top of the files every run protects, such as CI workflows, `CLAUDE.md` and lock files. See [Guardrails](guardrails.md#protected-files-and-commands). Changes apply to new runs.

The lock covers Claude's edit tools only. A shell command on the allowed list, such as `php` or `python`, can still write a locked file. Foreman's gate doesn't check locked paths afterwards. See [Known gaps](security.md#known-gaps).

## Instructions

The prompt given to the agent at the start of a run. The form shows the first few lines, the line count, and the placeholders the prompt uses.

- **Open full editor** shows the whole prompt for editing.
- **Compare with default** shows how the prompt differs from Foreman's default. Added lines are marked `+` and removed lines `−`. The button shows only when the prompt has been edited.

These placeholders are replaced:

| Placeholder | Replaced with |
| --- | --- |
| `{{issue}}` | The issue number |
| `{{issue_text}}` | The issue's title and body, cleaned up and marked as untrusted data. See [Sanitised issue](guardrails.md#sanitised-issue) |
| `{{repo}}` | `owner/name` |
| `{{branch}}` | The run's branch, like `agent/issue-42` |
| `{{base}}` | The base branch |
| `{{human_label}}` | The human label |

A custom prompt must use `{{issue_text}}` to give the agent the issue. The agent can't read it with `gh issue view`, because every `gh` command is blocked.

The default prompt tells the agent to commit its work and write the result file described in [How a run ends](runs.md#how-a-run-ends). It also tells the agent to use the `test_looks_wrong` reason instead of changing a test that seems wrong.

The default prompt starts with `/mattpocock-skills:implement`. So it needs the `mattpocock-skills` Claude Code plugin. If you don't use that plugin, edit the instructions.

The review pass after the gate uses `/code-review` and `/simplify`, plus `/security-review` for [risk tier 3](guardrails.md#risk-tiers). These come with Claude Code. The pass has its own prompt, so a custom prompt doesn't change it.

**Restore default** puts the default prompt back. If you save the default unchanged, Foreman stores nothing. Then later improvements to Foreman's default prompt reach this repository on their own.

## After the PR

### Watch PRs

The **Fix failing checks and review-bot comments** switch. On by default. After the agent opens a PR, Foreman follows it through CI and review bots and runs fix-up rounds. Off means the run ends as **PR opened**. The number of fix-up rounds is one setting for all repositories, in [Global settings](global-settings.md#pr-watching). See [PR watching](pr-watching.md).

### Remove the worktree when done

On by default. Foreman deletes the worktree when it's finished with the PR. That's when the PR is ready, handed to you, or cancelled, or straight away if Watch PRs is off. A failed run always keeps its worktree, so you can look inside it.

### Sandbox

Off by default. When it's on, Foreman runs the agent's shell commands in Claude Code's sandbox. A *sandbox* is a limit the operating system puts on a command. Build scripts those commands start, such as an npm `postinstall` script, run in it too.

This was tested only on macOS, with Claude Code 2.1.316. The test covered a commit, GPG signing, an npm install, Foreman's hooks, a blocked host, and the blocked writes and reads listed below. It didn't cover Composer, pip or Cargo installs, or a Mac where the sandbox isn't available.

- **Writes** stay in the worktree, plus the parts of the checkout's `.git` folder that a commit writes, and the package caches below. A script can't change the rest of your home folder, your other checkouts, or the repository's git hooks and git config.
- **Reads** of `~/.gnupg`, `~/.ssh` and `~/.config/gh` are blocked, since they hold keys and tokens. Everything else in your home folder can still be read.
- **Network.** Commands can reach only the hosts on the *allowlist*. That is GitHub plus the package registries, the servers packages download from, for the stacks Foreman found. They are Packagist, npm and Yarn, crates.io, and PyPI.
- **No way out.** The agent can't ask to run a command outside the sandbox. Foreman also asks Claude Code to fail the run when the sandbox isn't available.
- **Package caches.** Package managers keep downloaded packages in a cache folder, and the sandbox blocks yours. So the agent's installs use `storage/foreman/cache/` instead. A script in one repository then can't tamper with the caches your projects outside Foreman use. All repositories in Foreman share this one cache. Each tool's first install starts with an empty cache. Cargo is the exception: it keeps using `~/.cargo/registry` and `~/.cargo/git`.
- **Signed commits.** Git signs commits through `gpg-agent`, a background program that holds your unlocked key. The sandbox blocks it, so the agent commits without signing. If your git config signs commits, Foreman signs every commit it hasn't pushed yet, just before it pushes.

Git dependencies over SSH fail, because the agent has no SSH agent and can't read `~/.ssh`. Use HTTPS URLs for them. Turn the sandbox off for a repository that needs another host, such as a private registry.

The sandbox still leaves gaps. A script can still read your `gh` token straight from the keychain, and the setup script and Foreman's checks run outside it. See [Known gaps](security.md#known-gaps) before you rely on it.

## Archive and remove

The box at the bottom of the settings:

- **Archive** takes the repository off the dashboard and stops it, but keeps its settings and history. It's disabled while runs are active or PRs are being watched. Find archived repositories on the **Archive** page.
- **Remove from Foreman** deletes the repository and its run history from Foreman. Nothing on GitHub is touched.
