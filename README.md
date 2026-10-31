# Foreman

Turn labelled GitHub issues into pull requests, using Claude Code on your own machine.

![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Livewire 4](https://img.shields.io/badge/Livewire-4-FB70A9?logo=livewire&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

Label an issue `ready-for-agent` and Foreman picks it up. It runs Claude Code headlessly in an isolated git worktree and opens a PR. It then stays with that PR until CI is green and the review bots have been answered. If the agent gets stuck, it hands the issue back to you with an explanation.

Foreman runs locally. It uses the `gh` and `claude` logins you already have, so there are no API tokens to configure.

---

## What it does

- **Label-driven queue.** Open issues carrying your ready label are taken oldest first. Issues blocked by other open issues are skipped.
- **Isolated worktrees.** Each run gets its own git worktree and branch (`agent/issue-<n>`), so several issues can be worked on at once without touching your checkout.
- **Guard rails per repository.** Concurrency, poll interval, daily spend budget, model, max turns, max minutes, and the exact tools the agent may use.
- **PR watching.** After a PR opens, Foreman waits for CI and review bots to settle. It then runs fix-up rounds that repair failing checks and reply to each bot thread.
- **Usage-aware scheduling.** On a Claude subscription, Foreman reads each profile's `/usage` numbers. It holds new runs before you hit a limit, so your interactive sessions keep working.
- **Hand-off and resume.** When the agent is blocked, it comments on the issue and adds `needs-human`. Once you reply, **Continue after my reply** resumes the same Claude session.
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
Waiting ─► Setting up ─► Writing code ─► Running checks
            worktree +     claude -p       tests / static
            setup script                   analysis
        │
        ├─► PR opened ─► Watching PR ─► PR ready      (checks pass, no open bot threads, or merged)
        │                    │
        │                    └─► fix-up round ─► Watching PR …  (up to FOREMAN_PR_MAX_ROUNDS)
        │
        ├─► Needs you   (agent added needs-human, or rounds ran out)
        └─► Failed      (error, timeout, or no PR and no flag)
```

A few design choices are worth knowing about:

- **GitHub is the source of truth.** When the agent exits, Foreman checks GitHub for an open PR on the branch or a `needs-human` label. It does not trust what the agent says it did. A run that ends with neither one fails, and the agent's last message goes on the issue.
- **Polling is free.** The scheduler only calls `gh`. Claude is started only after an issue is found and claimed.
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
| The `mattpocock-skills` Claude Code plugin | The default prompt starts with `/mattpocock-skills:implement` and also uses `/simplify`. To avoid needing the plugin, edit the prompt for each repository. |

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

Open the dashboard and click **Add repository**. Enter the path to a local clone and click **Detect** to fill in the GitHub `owner/name`. Review the defaults, then save. Foreman creates the three labels on GitHub if they don't exist.

**4. Give it an issue.**

Pick a small, well-specified issue and add the `ready-for-agent` label to it. The issue body is the spec, and the agent stays strictly inside it.

**5. Start the repository.**

Click **Start** on the repository's row. Foreman checks for ready issues straight away, and you'll see the run move through the stations. Click it to follow the log. When it finishes, there's a PR linked to the issue.

---

## Configuration

### Repository settings

Each repository has its own settings in the dashboard. To change them, click the gear icon on the repository's row.

| Setting | Default | Description |
| --- | --- | --- |
| Local path | — | A local git checkout. `~/` is expanded. |
| GitHub repo | detected | `owner/name`. |
| Base branch | `main` | Branches are created from `origin/<base>`. |
| Ready label | `ready-for-agent` | Issues with this label are queued. |
| Working label | `agent-working` | Replaces the ready label while a run owns the issue. |
| Human label | `needs-human` | Added when the agent or Foreman needs you. |
| Concurrency | `1` | Runs at once for this repository (1–8). Can also be changed with **−** / **+** on the dashboard. |
| Poll interval | `10` min | How often to look for ready issues. |
| Daily budget | none | Stop starting runs once today's cost reaches this amount (USD). |
| Model | `sonnet` | Passed to `claude --model`. |
| Claude profile | first profile | Which entry in `CLAUDE_PROFILES` to run under. See [Claude profiles](#claude-profiles). |
| Max turns | `40` | Passed to `claude --max-turns`. |
| Max minutes | `45` | Wall-clock limit. The agent is stopped and the run fails when it's reached. |
| Allowed tools | see below | Passed to `claude --allowedTools`. |
| Setup script | see below | Bash, run in each new worktree with `-e`, with a 15-minute timeout. |
| Prompt | `resources/prompts/default.md` | The instructions given to the agent. **Restore default** resets it. |
| Clean up worktrees | on | Remove the worktree once a PR is opened. |
| Watch PRs | on | Follow the PR through CI and review bots. See [PR watching](#pr-watching). |

The default **allowed tools** let the agent read and edit files, use skills and sub-agents, and run `git`, `composer`, `php artisan`, `./vendor/bin/*`, `npm`/`npx`/`pnpm`/`yarn`, `gh issue`, and `gh pr create`. It can't run anything else.

The default **setup script** is:

```bash
cp .env.example .env
composer install --no-interaction --quiet
php artisan key:generate --quiet
```

The **prompt** supports these placeholders:

| Placeholder | Replaced with |
| --- | --- |
| `{{issue}}` | Issue number |
| `{{repo}}` | `owner/name` |
| `{{branch}}` | The run's branch, e.g. `agent/issue-42` |
| `{{base}}` | Base branch |
| `{{human_label}}` | The human label |

A prompt left unchanged is stored as "default", so later improvements to `resources/prompts/default.md` reach that repository automatically.

### Global controls

These are on the dashboard rather than per repository:

- **Pause** stops all new runs. Runs in progress finish, and PRs are still watched.
- **Daily budget** caps total spend across all repositories for the day.
- **Run anyway** appears when a profile is held for usage. It lets the next check start runs on that profile regardless of the hold.

### Environment variables

Foreman-specific settings live in `.env`. Everything else is standard Laravel.

| Variable | Default | Description |
| --- | --- | --- |
| `CLAUDE_PROFILES` | empty | Claude Code profiles as `name:config-dir` pairs, comma-separated. |
| `FOREMAN_USAGE_FIVE_HOUR_CEILING` | `0.80` | Hold a profile once its 5-hour window reaches this fraction. |
| `FOREMAN_USAGE_WEEKLY_PACE_MARGIN` | `0.10` | How far ahead of the week's pace usage may run before holding. |
| `FOREMAN_USAGE_WEEKLY_CAP` | `0.90` | Hold at this weekly fraction regardless of pace. |
| `FOREMAN_USAGE_PROBE_MINUTES` | `15` | Refresh a profile's usage numbers if they're older than this before starting runs. |
| `FOREMAN_PR_MAX_ROUNDS` | `2` | Fix-up rounds per PR before handing it to a human. |
| `FOREMAN_PR_QUIET_MINUTES` | `5` | How long review bots must be quiet before a round starts. |
| `FOREMAN_PR_TIMEOUT_MINUTES` | `30` | Start a round this long after a push even if checks are still pending. |

### Claude profiles

If you use more than one Claude Code login, for example personal and work accounts, define them as profiles:

```dotenv
CLAUDE_PROFILES="personal:~/.claude-personal,work:~/.claude-work"
```

A repository on a profile runs Claude with `CLAUDE_CONFIG_DIR` set to that folder. Usage limits, gauges and token totals are all tracked per profile. With no profiles defined, every repository uses your default `~/.claude` login.

---

## Usage limits

On a subscription, Foreman runs share your `/usage` limits with your interactive Claude sessions. Foreman reads the `rate_limit_event` messages Claude emits while it works. Before starting runs on a profile with stale numbers, it sends a one-turn Haiku probe. Using those numbers, it holds new runs on a profile when any of these is true:

- the 5-hour window is at or above the ceiling (80%),
- the weekly window is at or above the cap (90%),
- the weekly window is ahead of pace: more than the margin (10%) above the share of the week that has passed. For example, two days into the week the pace is about 29% + 10%.

A held profile shows a gauge in the warning colour and the reason on the dashboard. The repositories on it stay idle until the window resets or you click **Run anyway**. If Claude stops a run because it hit a limit, the profile is held instead of failing the rest of the queue one issue at a time.

---

## PR watching

With **Watch PRs** on, a run that opens a PR moves to **Watching PR**. The watcher only calls `gh` and costs no tokens. Once no check is pending and review bots have been quiet for `FOREMAN_PR_QUIET_MINUTES`, it decides what happens next:

| PR state | Result |
| --- | --- |
| Merged, or all checks pass with no open bot threads | **PR ready** |
| Failing checks or open bot threads | A fix-up round: the agent resumes in the same worktree to fix them |
| Still outstanding after `FOREMAN_PR_MAX_ROUNDS` rounds | A summary comment on the PR, `needs-human` added, **Needs you** |
| Someone else pushed to the branch | **Needs you**: Foreman leaves the PR to you |
| Closed without merging | **Cancelled** |

In a round, the agent gives a verdict for each bot thread: **fixed**, **declined** (with a reason), or **unsure**. Foreman posts the reply. It resolves the thread for fixed and declined, and leaves unsure threads open for you. The agent is never allowed to comment on, reply to or resolve threads itself.

---

## Troubleshooting

The dashboard runs preflight checks and lists any problems at the top, along with how to fix each one. Click **Check again** after fixing something.

| Problem | Fix |
| --- | --- |
| *git / Claude Code isn't on the PATH* | Start Foreman from a terminal where `git --version` and `claude --version` work. |
| *GitHub CLI isn't installed or isn't logged in* | Run `gh auth login`, then restart Foreman. |
| *The scheduler isn't running* | Start everything with `composer run foreman`, not just `php artisan serve`. |
| *Run(s) waiting over two minutes for a worker* | All workers are busy, or none are running. Add one with `php artisan queue:work --queue=runs --timeout=0`. |
| A repository shows an error | The last `gh` call for it failed. The message is shown on its row. |
| A run failed | Open it. The error and the agent's last message are on the run page and on the issue. **Start over** requeues the issue. |

Run files are kept under `storage/foreman/`:

- `logs/run-<id>.log`: the readable log shown in the UI.
- `logs/run-<id>.jsonl`: Claude's raw `stream-json` output.
- `worktrees/<owner>-<name>/issue-<n>/`: worktrees. A failed run keeps its worktree here so you can inspect it.

---

## Security notes

- **There is no login.** The dashboard is meant for `localhost` only. Don't expose it to a network.
- **The agent acts as you.** It uses your `gh` and git credentials, so it can push branches and open PRs anywhere your account can. The **allowed tools** list is what keeps it in bounds, so review it before widening it.
- **Setup scripts run unsandboxed.** They run as your user in each worktree.

---

## Development

```bash
composer run dev     # web server, queue, logs and Vite with hot reload
composer test        # run the test suite
./vendor/bin/pint    # format PHP
```

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
