<p align="center">
  <img src="public/icon-512.png" alt="Foreman logo: a figure in a yellow hard hat" width="160">
</p>

<div align="center">

# Foreman

Turn labelled GitHub issues into pull requests, using Claude Code on your own machine.

![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Livewire 4](https://img.shields.io/badge/Livewire-4-FB70A9?logo=livewire&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

</div>

Label an issue `ready-for-agent` and Foreman picks it up. It runs Claude Code headlessly in an isolated git worktree and opens a PR. It then stays with that PR until CI is green and the review bots have been answered. If the agent gets stuck, it hands the issue back to you with an explanation.

Foreman runs locally. It uses the `gh` and `claude` logins you already have, so there are no API tokens to configure.

---

## What it does

- **Label-driven queue.** Open issues carrying your ready label are taken oldest first. Issues blocked by other open issues are skipped.
- **Isolated worktrees.** Each run gets its own git worktree and branch (`agent/issue-<n>`), so several issues can be worked on at once without touching your checkout.
- **Guard rails per repository.** Concurrency, poll interval, daily spend budget, model, max turns, max minutes, and the exact tools the agent may use.
- **PR watching.** After a PR opens, Foreman waits for CI and review bots to settle. It then runs fix-up rounds that repair failing checks and reply to each bot thread.
- **Usage-aware scheduling.** On a Claude subscription, Foreman reads each profile's `/usage` numbers. It holds new runs before you hit a limit, so your interactive sessions keep working.
- **Hand-off and resume.** When the agent is blocked, it says so in its result file. Foreman then comments on the issue and adds `needs-human`. Once you reply, **Continue after my reply** resumes the same Claude session.
- **A dashboard for all of it.** Live run status, a grouped log for each run, token and cost totals, usage gauges for each profile, a global pause, and a global daily budget.

---

## How it works

```
GitHub issue labelled ready-for-agent
        │  foreman:poll (every minute, only calls gh — no tokens)
        ▼
claimed: label swapped to agent-working
        │
        ▼
Waiting ─► Setting up ─► Writing code ─► Running checks ─► Reviewing
            worktree +     claude -p       tests / static    Foreman's own gate:
            setup script                   analysis          verify, integrity, size
        │
        ├─► Watching PR ─► PR ready      (checks pass, no open bot threads, or merged)
        │        │
        │        └─► fix-up round ─► Watching PR …  (up to FOREMAN_PR_MAX_ROUNDS)
        │
        ├─► PR opened   (Watch PRs is off)
        ├─► Needs you   (the agent asked for help, Foreman's checks failed, or rounds ran out)
        └─► Failed      (error, timeout, or no readable result file)
```

A few design choices are worth knowing about:

- **The agent never touches GitHub.** It commits in its worktree and writes a result file, `.foreman/result.json`. Foreman reads the file, runs its own checks, and then pushes, opens the PR, comments and labels. It only trusts checks it ran itself. A run with no readable result file fails, and the agent's last message goes on the issue.
- **Polling is nearly free.** The scheduler only calls `gh`, so checking costs no tokens. The one exception is a usage probe: a one-turn Haiku call made when ready issues are waiting and the profile's usage numbers are stale. See [Usage limits](docs/usage-limits.md#where-the-numbers-come-from).
- **Workers are plain Laravel queue workers.** `composer run foreman` starts three, so up to three runs execute at once across all repositories. Each repository's concurrency setting limits how many of those slots it can use.
- **Dead runs are reaped.** A run whose worker stops sending heartbeats for 5 minutes is marked failed, and its issue is released. This happens when a laptop sleeps or a process is killed.

---

## Requirements

| Tool | Why |
| --- | --- |
| PHP 8.3+ and Composer | Runs the app |
| Node.js and npm | Builds the UI assets and runs the process supervisor |
| `git` | Worktrees and branches |
| [GitHub CLI](https://cli.github.com/) (`gh`), logged in | Reads issues, swaps labels, opens and watches PRs |
| [Claude Code](https://docs.claude.com/en/docs/claude-code) (`claude`) on your `PATH` | Does the work |
| The `mattpocock-skills` Claude Code plugin | The default prompt starts with `/mattpocock-skills:implement`. To avoid needing the plugin, edit the prompt for each repository. |

Every repository you add needs a local clone with a GitHub remote. It also needs a setup script that can prepare a fresh worktree, for example by copying `.env` and installing dependencies.

---

## Quickstart

This walks you from a fresh clone to your first agent-opened PR.

**1. Install Foreman.**

```bash
git clone https://github.com/lonnieezell/foreman.git
cd foreman
composer run setup
```

`setup` installs PHP and npm dependencies, creates `.env` and an app key, runs the migrations (SQLite by default), and builds the assets.

**2. Start everything.**

Run this from a terminal where `gh auth status` and `claude --version` both work:

```bash
composer run foreman
```

This starts the web UI at <http://localhost:8787>, the scheduler, and three run workers, all in one terminal.

**3. Add a repository.**

Open the dashboard and click **Add repository**. Enter the path to a local clone and click **Detect** to fill in the GitHub `owner/name`. Review the defaults, then save. Foreman creates its three labels on GitHub, or updates their colour and description if they already exist.

**4. Give it an issue.**

Pick a small, well-specified issue and add the `ready-for-agent` label to it. The issue body is the spec, and the agent stays strictly inside it.

**5. Start the repository.**

Click **Start** on the repository's row. Foreman checks for ready issues straight away, and you'll see the run move through the stations. Click it to follow the log. When it finishes, there's a PR linked to the issue.

---

## Updating

After you pull new changes, run:

```bash
composer run update-app
```

It installs any new dependencies, runs new migrations and rebuilds the assets. Then stop `composer run foreman` with Ctrl+C and start it again. The queue workers keep the old code until they restart.

## Documentation

The full docs are in [`docs/`](docs/). They're also built into the app: click **Docs** in Foreman's header.

- [Getting started](docs/getting-started.md): from an empty dashboard to your first PR.
- [Runs and statuses](docs/runs.md): the stations, what each status means, and the buttons.
- [Guardrails](docs/guardrails.md): the checks Foreman runs on an agent's work, and the rules for each language stack.
- [Repository settings](docs/repository-settings.md): every setting for a repository, including the setup script, allowed tools and prompt.
- [Global settings](docs/global-settings.md): pause, spend limits, notifications and environment variables.
- [Usage limits and profiles](docs/usage-limits.md): how Foreman shares your Claude subscription and when it holds runs.
- [PR watching](docs/pr-watching.md): how Foreman follows a PR through CI and review bots.
- [Troubleshooting](docs/troubleshooting.md): dashboard warnings, failed runs, and where run files live.

---

## Security notes

- **There is no login.** The dashboard is meant for `localhost` only. Don't expose it to a network.
- **The agent doesn't act with your push and `gh` rights.** It commits in its worktree and writes a result file. Foreman reads that file, then pushes the branch and opens the PR itself. The agent's environment has no push URL, no git credential helper and no GitHub tokens, and the default **allowed tools** list has no `gh` and no `git push`. Review the list before widening it. See [Guardrails](docs/guardrails.md#foreman-pushes-not-the-agent).
- **Setup and verify scripts run unsandboxed.** They run as your user in each worktree. The [Sandbox](docs/repository-settings.md#sandbox) setting covers only the agent's shell commands.

---

## Development

```bash
composer run dev     # web server, queue, logs and Vite with hot reload (no scheduler, no runs queue)
composer test        # run the test suite
./vendor/bin/pint    # format PHP
```

`composer run dev` is for working on the UI. It has no scheduler, and its queue worker doesn't listen on the `runs` queue, so no runs happen. Use `composer run foreman` to run Foreman for real.

Run a single poll by hand with `php artisan foreman:poll`. Add `--repo=<id>` to check one repository and `--force` to ignore its poll interval.

---

## License

Foreman is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

<div align="center">

### Support Foreman

If Foreman saves you time, please consider sponsoring its development.

<a href="https://github.com/sponsors/lonnieezell">
  <img src="https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Sponsor on GitHub">
</a>

</div>
