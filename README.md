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

The agent never touches GitHub. It commits in its worktree and writes a result file. Foreman runs its own checks on the work, then pushes and opens the PR itself. [How Foreman works](docs/how-foreman-works.md) explains each step and why it's there.

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

Install Foreman, then start it from a terminal where `gh auth status` and `claude --version` both work:

```bash
git clone https://github.com/lonnieezell/foreman.git
cd foreman
composer run setup
composer run foreman
```

`setup` installs dependencies, creates `.env`, runs the migrations (SQLite by default) and builds the assets. `foreman` starts the web UI at <http://localhost:8787>, the scheduler and three run workers, all in one terminal.

Then follow [Getting started](docs/getting-started.md) to add a repository and get your first PR.

---

## Updating

After you pull new changes, run:

```bash
composer run update-app
```

It installs any new dependencies, runs new migrations and rebuilds the assets. Then stop `composer run foreman` with Ctrl+C and start it again. The queue workers keep the old code until they restart.

## Documentation

The full docs are in [`docs/`](docs/). They're also built into the app: click **Docs** in Foreman's header.

**Start here**

- [Getting started](docs/getting-started.md): from an empty dashboard to your first PR.

**Understand it**

- [How Foreman works](docs/how-foreman-works.md): the path from issue to PR, and why the agent never touches GitHub.
- [Guardrails](docs/guardrails.md): the checks Foreman runs on an agent's work, and what each one doesn't catch.
- [Usage limits and profiles](docs/usage-limits.md): how Foreman shares your Claude subscription and when it holds runs.
- [PR watching](docs/pr-watching.md): how Foreman follows a PR through CI and review bots.
- [Security and known gaps](docs/security.md): what protects you, and where that protection stops.

**Do a task**

- [Setting up CI in your repo](docs/ci-setup.md): add Foreman's CI workflow to a repository.
- [Troubleshooting](docs/troubleshooting.md): dashboard warnings, failed runs, and where run files live.

**Look it up**

- [Runs and statuses](docs/runs.md): the stations, what each status means, and the buttons.
- [Repository settings](docs/repository-settings.md): every setting for a repository.
- [Global settings](docs/global-settings.md): pause, spend limits, notifications and environment variables.
- [Stack rules](docs/stack-rules.md): the rules Foreman loads for PHP, TypeScript, Rust and Python.
- [Commands](docs/commands.md): composer scripts, artisan commands and the test-integrity script.

---

## Security notes

- **There is no login.** The dashboard is meant for `localhost` only. Don't expose it to a network.
- **The agent doesn't act with your push and `gh` rights.** Foreman pushes and opens the PR itself. Review the **allowed tools** list before widening it.
- **Setup and verify scripts run unsandboxed.** They run as your user in each worktree.
- **Shell tools get past some guardrails.** An allowed tool such as `php` or `python` can write files the edit rules protect.

Read [Security and known gaps](docs/security.md) before you rely on Foreman with repositories where outsiders can open issues.

---

## Development

```bash
composer run dev     # web server, queue, logs and Vite with hot reload (no scheduler, no runs queue)
composer test        # run the test suite
./vendor/bin/pint    # format PHP
```

`composer run dev` is for working on the UI. It has no scheduler, and its queue worker doesn't listen on the `runs` queue, so no runs happen. Use `composer run foreman` to run Foreman for real.

Every command and its options are in [Commands](docs/commands.md).

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
