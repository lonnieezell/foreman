# Repository settings

Each repository has its own settings. To open them, click the gear icon on the repository's row, or click its name.

## Where the code lives

### Local checkout

A git clone on this machine. `~/` is expanded. **Browse…** opens your system's folder picker. **Detect** reads the GitHub remote from the folder.

Foreman never works in this folder directly. Each run gets its own worktree under `storage/foreman/worktrees/`.

### GitHub repository

The repository on GitHub, as `owner/name`. **Detect** fills it in.

### Name in Foreman

The name shown on the dashboard. **Detect** fills it in from the repository name if it's empty.

### Branch PRs target

The base branch. Default: `main`. Each run's branch starts from `origin/<base>`, and the PR targets it.

## Pace and spend

### Issues at a time

How many runs this repository may have active at once, from 1 to 8. Default: 1. You can also change it with **−** / **+** on the dashboard.

All repositories share three workers. So more than three runs at once only helps if you start more workers. See [Troubleshooting](troubleshooting.md#runs-stay-on-waiting).

### Check for issues every (minutes)

How often Foreman looks for ready issues, from 1 to 1440. Default: 10. Checks only call `gh`, so they cost no tokens.

### Daily limit (USD)

Stop starting runs for this repository once today's spend reaches this amount. Empty means no limit. When the limit is reached, the row shows "Daily limit … reached, paused until tomorrow". Runs already in progress finish.

Spend is the cost Claude Code reports for each run. On a subscription it's an estimate, but it's still useful for comparing runs. There's also a limit across all repositories. See [Global settings](global-settings.md#spend).

### Limit per run (USD)

The most a single run may spend. Empty means no limit. Passed to `claude --max-budget-usd`.

The cap counts sub-agents too. It may also overshoot by one response, because Claude checks it between responses. A run that hits the cap stops and usually ends as Failed.

### Model

Haiku, Sonnet, Opus or Fable. Default: Sonnet. Passed to `claude --model`.

You can also change the model, and the effort below, from the repository's row on the dashboard. Click the model name under the repository's name, pick new values and press Save. The change applies to new runs only.

### Effort

How hard Claude thinks. Default, Low, Medium, High, XHigh or Max. Passed to `claude --effort`.

**Default** leaves `--effort` out, so Claude uses its own default. Every model accepts every level. Higher levels let Claude think longer, which usually costs more tokens.

Like the model, effort applies to new runs. A run that is already queued keeps the model and effort it was created with.

### Claude profile

Which Claude Code login to run under. The menu lists the profiles in `CLAUDE_PROFILES`. With no profiles set up, it shows **Default (~/.claude)**. See [Usage limits and profiles](usage-limits.md#claude-profiles).

### Max turns per issue

The most turns Claude may take in one run, from 1 to 500. Default: 40. Passed to `claude --max-turns`. A *turn* is one round of Claude replying or using a tool. A run that runs out of turns usually ends as Failed.

### Max minutes per issue

A wall-clock limit for the agent, from 1 to 600. Default: 45. When it's reached, Foreman stops Claude and the run fails with "Hit the 45-minute limit." The setup script doesn't count toward it.

## Labels

Foreman uses three GitHub labels. It creates them when you save, if they don't exist yet.

| Setting | Default | Meaning |
| --- | --- | --- |
| Ready for the agent | `ready-for-agent` | Add this to an issue to queue it. |
| Being worked on | `agent-working` | Replaces the ready label while a run owns the issue. |
| Needs a human | `needs-human` | Added when the agent or Foreman needs you. |

The working and human labels must differ from the ready label. If the labels can't be created, the settings still save, and a warning shows at the top of the form.

## How the agent works

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

The setup script runs as you, with no sandbox.

### Verify script

Bash that decides when the agent is done. The agent can't stop until this script exits 0 and the [test-integrity check](guardrails.md#test-integrity) passes. It runs in the worktree, with `-e`, so it stops at the first failing line. It has a 25-minute timeout.

Leave it empty to use the checks Foreman finds for the stacks in the worktree. For a Laravel app with Pint, PHPStan and Pest, and a base branch of `main`, that is:

```bash
(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/pint --test --)
vendor/bin/phpstan analyse
vendor/bin/pest
```

The first line runs Pint only on the files the branch changed, so formatting problems already on the base branch don't fail the run. See [What the default checks cover](guardrails.md#what-the-default-checks-cover).

When you add a repository, or open the settings of one that has no script yet, the form fills in this default so you can see and edit it. If you save it unchanged, Foreman stores nothing, so the default keeps following the base branch and the stacks it finds. The [stack rules](guardrails.md#stack-rules) table lists the default for each language.

Use check-only commands, such as `pint --test`, so the script never changes files. Match what your CI runs.

The code icon (`< >`) next to the label copies a prompt for Claude Code, like the one for the setup script. Paste it into `claude` in the project, and Claude works out a script that matches your CI.

If the checks keep failing, the agent is sent back three times and then allowed to stop. See [Troubleshooting](troubleshooting.md#stop-hook-gave-up).

The verify script runs as you, with no sandbox. Changes apply to new runs.

### Allowed tools

The tools Claude may use, passed to `claude --allowedTools`. Anything not listed is refused. The default lets the agent:

- read and edit files, search, and use skills and sub-agents
- run the git commands that inspect and commit: `git status`, `git diff`, `git log`, `git show`, `git add` and `git commit`
- run `composer`, `php`, `./vendor/bin/*`, `npm`, `npx`, `pnpm` and `yarn`
- run Rust tools (`cargo`, `rustfmt`) and Python tools (`python`, `python3`, `pytest`, `ruff`, `mypy`, `pyright`, `uv` and `poetry`)

The list has no `gh` and no `git push`. Foreman pushes the branch and opens the PR itself, and the agent's environment can't push anyway. See [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent). Runs use Claude's `dontAsk` permission mode, so anything not on the list is refused without asking. If one command in a chained Bash call isn't on the list, the whole call is refused. The default prompt tells the agent to split the call and retry without that command.

Fix-up rounds use the same list. Foreman reads the CI results and puts them in the prompt, so the agent needs no `gh` tools. See [PR watching](pr-watching.md).

#### Old tool lists

A repository saved before this change may still list `Bash(git:*)`, `Bash(gh issue:*)` or `Bash(gh pr create:*)`. Foreman doesn't touch saved lists. Instead, the settings page shows a notice under the field suggesting **Restore default**. The button puts the new default in the field. Click **Save** to keep it. Skip it if you widened the list on purpose.

A saved list also misses entries added to the default later, such as `Bash(php:*)`. Without it, commands like `php -v` or `php -l` are refused. Add it to the field by hand and click **Save**.

### Locked paths

Files the agent may not edit, one pattern per line. Optional. Patterns are relative to the repository root. `*` matches within one folder, and `**` matches across folders. A pattern ending in `/` locks the whole folder. For example:

```text
database/migrations/**
tests/Feature/BillingTest.php
```

Foreman blocks the edit and tells the agent which path is locked. This is on top of the files every run protects, such as CI workflows, `CLAUDE.md` and lock files. See [Guardrails](guardrails.md#protected-files-and-commands). Changes apply to new runs.

### Instructions

The prompt given to the agent at the start of a run. These placeholders are replaced:

| Placeholder | Replaced with |
| --- | --- |
| `{{issue}}` | The issue number |
| `{{issue_text}}` | The issue's title and body, cleaned up and marked as untrusted data. See [Sanitised issue](guardrails.md#sanitised-issue) |
| `{{repo}}` | `owner/name` |
| `{{branch}}` | The run's branch, like `agent/issue-42` |
| `{{base}}` | The base branch |
| `{{human_label}}` | The human label |

A custom prompt that still tells the agent to run `gh issue view` skips the cleanup, because the agent reads the raw issue itself. Use `{{issue_text}}` instead.

The default prompt tells the agent to commit its work and write the result file described in [How a run ends](runs.md#how-a-run-ends). It also tells the agent to use the `test_looks_wrong` reason instead of changing a test that seems wrong.

The default prompt starts with `/mattpocock-skills:implement`. So it needs the `mattpocock-skills` Claude Code plugin. If you don't use that plugin, edit the instructions.

The review pass after the gate uses `/code-review`, `/simplify` and `/security-review`, which come with Claude Code. It has its own prompt, so a custom prompt doesn't change it. See [Risk tiers](guardrails.md#risk-tiers).

**Restore default** puts the default prompt back. If you save the default unchanged, Foreman stores it as "default". Then later improvements to Foreman's default prompt reach this repository on their own.

### Watch PRs

On by default. After the agent opens a PR, Foreman follows it through CI and review bots and runs fix-up rounds. Off means the run ends as **PR opened**. See [PR watching](pr-watching.md).

### Remove the worktree once Foreman is done with the PR

On by default. Foreman deletes the worktree when it's finished with the PR. That's when the PR is ready, handed to you, or cancelled, or straight away if Watch PRs is off. A failed run always keeps its worktree, so you can look inside it.

## Archive and remove

The buttons at the top of the settings:

- **Archive** takes the repository off the dashboard and stops it, but keeps its settings and history. It's disabled while runs are active or PRs are being watched. Find archived repositories on the **Archive** page.
- **Remove from Foreman** deletes the repository and its run history from Foreman. Nothing on GitHub is touched.
