# Runs and statuses

A *run* is one attempt at one issue. An issue can have several runs: a first try, a retry, a continue after your reply, or a fix-up round on its PR. The run page lists them all at the top under **Attempts**.

## How a run starts

Every minute, Foreman's scheduler looks at each started repository that is due for a check. For each one it:

1. Asks GitHub for open issues with the ready label (`ready-for-agent`), oldest first. It skips issues blocked by other open issues.
2. Takes as many as the repository has free slots for. A slot is one of its **Issues at a time**.
3. *Claims* each issue by swapping the ready label for the working label (`agent-working`).
4. Queues a run for a worker to pick up.

This only calls `gh`, so checking costs no tokens. Claude starts only after an issue is claimed.

Nothing is claimed while Foreman is paused, while a daily spend limit is reached, or while the repository's Claude profile is held for usage. See [Global settings](global-settings.md) and [Usage limits](usage-limits.md#when-runs-are-held).

## The stations

While a run is active, its issue sits on the repository's track at one of five stations.

### Waiting

The issue is claimed and waiting for a free worker. `composer run foreman` starts three workers, so up to three runs execute at once across all repositories.

### Setting up

Foreman fetches `origin` and creates a git worktree on the branch `agent/issue-<n>`. A *worktree* is a second working folder for the same repository, so your own checkout is never touched. The branch starts from the base branch. Then the repository's setup script runs in the worktree.

### Writing code

Claude Code is running in the worktree with the repository's instructions.

### Running checks

The agent has started running tests or static analysis. Foreman moves the run here when the agent runs one of these:

- **PHP:** `pest`, `phpunit`, `phpstan`, `pint` or `artisan test`
- **JavaScript/TypeScript:** `vitest`, `jest`, `playwright`, `eslint`, `prettier`, `biome` or `tsc`
- **Rust:** `cargo test`, `cargo nextest`, `cargo clippy`, `cargo fmt`, `cargo check` or `rustfmt`
- **Python:** `pytest`, `ruff`, `mypy` or `pyright`, including `uv run` and `poetry run` forms
- **Project scripts:** `npm`, `pnpm`, `yarn`, `bun` or `composer` running a `test`, `lint`, `typecheck`, `type-check`, `check` or `format` script, such as `npm run lint` or `composer test`

If your project uses other tools or script names, the run can finish without ever showing this station. That's harmless.

### Reviewing

The agent wrote a `pr` result. Before Foreman pushes, it runs the verify script and the integrity check itself, then checks the size of the change. Every run that ends in a PR passes through this station. If all three pass, Foreman sets the [risk tier](guardrails.md#risk-tiers) and resumes the agent for a review pass, still on this station. Next, the [scope judge](guardrails.md#scope-judge) checks that the change stays within the issue. If the judge passes it, Foreman runs the three checks again. If they pass, Foreman pushes and opens the PR with a footer that lists the checks and the judge's verdict. If not, the run ends as **Needs you**. See [Foreman's gate](guardrails.md#foremans-gate).

## How a run ends

The agent finishes by writing a result file in its worktree, `.foreman/result.json`. Foreman reads it and does everything on GitHub itself. The agent can't push, open PRs or use `gh`. See [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent).

The file says one of three things:

| Outcome | What the file holds | What Foreman does | The run ends as |
| --- | --- | --- | --- |
| `pr` | A title and a body | Runs [its own gate](guardrails.md#foremans-gate), then pushes the branch and opens the PR. It adds `Closes #<n>` to the top of the body if the agent left it out, and its own footer at the bottom. | **Watching PR**, or **PR opened** if Watch PRs is off |
| `needs_human` | A comment and a reason | Posts the comment on the issue and adds the human label (`needs-human`) | **Needs you** |
| `already_done` | A comment | Posts the comment on the issue and adds the human label | **Needs you** |

A missing file, or one that isn't valid JSON or lacks a field its outcome needs, fails the run. The agent's last message goes on the run page and in a comment on the issue.

The reason on a `needs_human` result shows in the run log. One reason is `test_looks_wrong`. It gives the agent an honest way out when a test seems wrong, instead of changing or skipping the test to get green.

## The statuses

### PR opened

Foreman opened a PR from the agent's work, and the repository doesn't watch PRs. Review the PR yourself.

You'll also see this on a run that handed its PR to a fix-up round. On a round, it reads **Fixes pushed · round 1/2**.

### Watching PR

Foreman is waiting for CI and review bots on the PR. No worker is busy and no tokens are spent while it waits. See [PR watching](pr-watching.md).

### PR ready

The PR was merged, or all its checks pass and no review-bot threads are open. Review and merge it.

### Needs you

Foreman stopped and wants a human. There are nine ways to get here:

- **The agent asked for help.** Its result file said `needs_human`, so Foreman commented on the issue and added the human label. This happens when the spec is unclear or contradicts the code, or when checks still fail after three attempts. Reply on the issue, then click **Continue after my reply**. The agent resumes the same Claude session in the same worktree, with everything it already knew. It reads your reply first. Or click **Start over** for a fresh run.
- **The agent thinks a test is wrong.** The reason is `test_looks_wrong`. The agent was told not to change, delete or skip the test, so it stopped and explained which test and why. Decide whether the test or the code is wrong, then reply on the issue.
- **Foreman's gate failed.** The verify script or the integrity check failed when Foreman ran it before pushing. Nothing was pushed. Foreman commented on the issue with the output. Fix the cause or reply on the issue, then click **Continue after my reply**.
- **The scope judge vetoed the change twice.** The [scope judge](guardrails.md#scope-judge) said the change goes beyond the issue, or weakens tests. The agent got one round to correct it, and the judge vetoed it again. Nothing was pushed. Foreman's comment on the issue gives the judge's reason. Decide whether the judge is right. Then reply on the issue and click **Continue after my reply**, or open the PR yourself from the worktree.
- **The scope judge gave no usable answer.** The judge call failed or gave an answer Foreman couldn't read, twice in a row. Nothing was pushed. The comment on the issue quotes what went wrong each time. This is usually a passing API problem. Click **Continue after my reply** to try again.
- **The change is too big.** The branch changes more lines than the size limit (`FOREMAN_MAX_PR_LINES`, 1,000 by default). Nothing was pushed, and the comment gives the count. Reply on the issue to ask the agent to split the work, or open the PR yourself from the worktree.
- **The issue was already done.** The result file said `already_done`. The comment says where the work is and whether its tests pass.
- **Fix-up rounds ran out.** The PR still has failing checks or open bot threads after the last round. Foreman left a summary comment on the PR. The PR is yours from here.
- **Someone else pushed to the PR's branch.** Foreman stops watching so it doesn't fight you over the branch.

### Failed

The run hit an error, ran out of time, or ended with neither a PR nor the human label. Foreman comments on the issue with the error, adds the human label, and keeps the worktree so you can look inside it. See [A run failed](troubleshooting.md#a-run-failed).

### Cancelled

Someone cancelled the run, stopped watching its PR, or closed the PR without merging.

### Other labels you may see

- **Cancelling**: you clicked **Cancel run**, and the worker is stopping Claude.
- **… · round 1/2**: the run is a fix-up round on a PR. The second number is the **Fix-up rounds per PR** setting.

## Actions

| Button | Where | What it does |
| --- | --- | --- |
| **Cancel run** | Run page, active runs | Stops Claude and ends the run as Cancelled. A run still Waiting is cancelled at once. |
| **Stop watching** | Run page, Watching PR | Ends the run as Cancelled. The PR stays open. |
| **Continue after my reply** | Run page and dashboard, Needs you | Swaps the human label back to the working label and resumes the agent's session. Use it when the agent asked a question before opening a PR. |
| **Start over** / **Retry** | Run page / dashboard, after a run ends without a PR | Puts the ready label back on the issue so it's queued again. If a slot is free, it starts at once. The new run starts from a fresh worktree. |
| **Dismiss** | Dashboard | Marks the issue as handled outside Foreman and moves it to **Handled**. Nothing is sent to GitHub. **Restore** undoes it. |

## The dashboard

Each repository row shows its track and a **Done today** strip. The strip counts today's finished issues by status.

The **Issues** column groups each issue by its latest run:

- **Needs your reply**, **Failed** and **Ready for review** need you. Their total shows in the stats row and in the browser tab title, like `(2) Foreman`.
- **In progress** and **Watching PRs** are still moving.
- **Handled** shows the last ten dismissed or cancelled issues.

## History

The **History** page lists every issue Foreman has worked on, newest first. It shows totals across all of an issue's runs: runs, turns, cost and tokens. Filter it by repository with the menu at the top.

## Archive

Archiving a repository takes it off the dashboard but keeps its settings and history. Archive it from its settings. You can't archive a repository while it has runs in progress or PRs being watched. Archived repositories are stopped. **Restore** brings one back, still stopped. **Remove** deletes it and its run history from Foreman. Nothing on GitHub is touched either way.
