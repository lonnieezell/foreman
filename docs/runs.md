# Runs and statuses

A *run* is one attempt at one issue. An issue can have several runs: a first try, a retry, a continue after your reply, or a fix-up round on its PR. The run page lists them all at the top under **Attempts**.

## How a run starts

Every minute, Foreman's scheduler looks at each started repository that is due for a check. For each one it:

1. Asks GitHub for open issues with the ready label (`ready-for-agent`), oldest first. It skips issues blocked by other open issues.
2. Takes as many as the repository has free slots for. A slot is one of its **Issues at a time**.
3. *Claims* each issue by swapping the ready label for the working label (`agent-working`).
4. Queues a run for a worker to pick up.

This only calls `gh`, so checking costs no tokens. The agent starts only after an issue is claimed. The one Claude call before that is a usage probe, a one-turn Haiku call made when the profile's usage numbers are stale. See [Usage limits](usage-limits.md#where-the-numbers-come-from).

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

The agent wrote a `pr` result. Before Foreman pushes, it runs the verify script and the integrity check itself, then checks the size of the change. Every run that opens a PR passes through this station. Fix-up rounds on a PR that is already open don't. If all three pass, Foreman sets the [risk tier](guardrails.md#risk-tiers) and resumes the agent for a review pass, still on this station. Next, the [scope judge](guardrails.md#scope-judge) checks that the change stays within the issue. If the judge passes it, Foreman runs the three checks again. If they pass, Foreman pushes and opens the PR with a footer that lists the checks and the judge's verdict. If not, the run ends as **Needs you**. See [Foreman's gate](guardrails.md#foremans-gate).

## How a run ends

The agent finishes by writing a result file in its worktree, `.foreman/result.json`. It says `pr`, `question`, `needs_human` or `already_done`. Foreman reads it and does everything on GitHub itself. A missing or unreadable file fails the run. See [How a run ends](how-foreman-works.md#how-a-run-ends) for what each outcome holds and what Foreman does with it.

## The statuses

### PR opened

Foreman opened a PR from the agent's work, and the repository doesn't watch PRs. Review the PR yourself.

The PR is a draft when the change is over the size limit (label `size:large`), when the scope judge gave no usable answer (label `scope:unchecked`), or when the judge vetoed it twice only for missing work (label `scope:incomplete`). The footer says why. See [The size limit](guardrails.md#the-size-limit) and [Scope judge](guardrails.md#scope-judge).

You'll also see this on a run that handed its PR to a fix-up round. On a round, it reads **Fixes pushed · round 1/2**.

### Watching PR

Foreman is waiting for CI and review bots on the PR. No worker is busy and no tokens are spent while it waits. See [PR watching](pr-watching.md).

### PR ready

The PR was merged, or all its checks pass and no review-bot threads are open. Review and merge it.

### Needs you

Foreman stopped and wants a human. There are ten ways to get here:

- **The agent has a question.** Its result file said `question`. The card shows a **question** badge, and an **Answer** button that opens the run page. See [Answering a question](#answering-a-question).
- **The agent asked for help.** Its result file said `needs_human`, so Foreman commented on the issue and added the human label. This happens when a command it needs is refused, or when checks still fail after three attempts. Reply on the issue, then click **Continue after my reply**. The agent resumes the same Claude session in the same worktree, with everything it already knew. It reads your reply first. Or click **Start over** for a fresh run.
- **The agent thinks a test is wrong.** The reason is `test_looks_wrong`. The agent was told not to change, delete or skip the test, so it stopped and explained which test and why. Decide whether the test or the code is wrong, then reply on the issue.
- **Foreman's gate failed.** The verify script or the integrity check failed when Foreman ran it before pushing. Nothing was pushed. Foreman commented on the issue with the output. Fix the cause or reply on the issue, then click **Continue after my reply**.
- **The scope judge vetoed the change twice.** The [scope judge](guardrails.md#scope-judge) said the change adds work the issue didn't ask for, or weakens tests. The agent got one round to correct it, and the judge vetoed it again. Nothing was pushed. Foreman's comment on the issue gives the judge's reason and the agent's answer. A veto only for missing work opens a draft PR instead. Decide whether the judge is right. Then reply on the issue and click **Continue after my reply**, or open the PR yourself from the worktree.
- **The issue was already done.** The result file said `already_done`. The comment says where the work is and whether its tests pass.
- **Fix-up rounds ran out.** The PR still has failing checks or open bot threads after the last round. Foreman left a summary comment on the PR. The PR is yours from here.
- **Someone else pushed to the PR's branch.** Foreman stops watching so it doesn't fight you over the branch.
- **The PR has merge conflicts.** Click **Fix conflicts** to have the agent merge the base branch and resolve them, or fix it yourself. See [Merge conflicts](pr-watching.md#merge-conflicts).
- **A conflict fix failed.** The agent couldn't resolve the conflicts, or Foreman's checks before the push failed. Nothing was pushed. The explanation is in the run's thread. The PR is yours from here.

### Answering a question

When the agent has a question, Foreman adds the human label to the issue and posts one fixed comment there. The questions stay in Foreman.

The run page shows a **Thread**. It holds the agent's questions and your messages for the whole chain of runs, oldest first. Its heading shows which round this is and the total cost across the chain.

1. Type your answer and click **Post**. It is saved straight away. Posting doesn't resume anything, so you can post more than one message.
2. Click **Continue**. The agent resumes the same session and reads every message you posted since its question. If you haven't posted anything, Foreman asks you to confirm first.

**Continue** starts one run only. A second click, or a click in another tab, shows an error instead of a second run. Only the issue's newest run can be continued, so a run you started over can't be resumed beside the fresh one.

The message box shows on the issue's newest run once it stops, not just on question runs. Older runs, and runs with a PR, take no messages. Use it to post a correction before **Continue after my reply** or **Start over**. **Start over** gives the fresh run every earlier question and answer for the issue, under "Decisions your operator already made".

Your answers live only in Foreman's database. They don't appear on the issue, so teammates can't see them on GitHub. The [scope judge](guardrails.md#scope-judge) doesn't see them either. If an answer widens the scope, the judge may veto the change. Edit the issue to settle it.

### Failed

The run hit an error, ran out of time, or ended without a readable result file. Foreman comments on the issue with the error, adds the human label, and keeps the worktree so you can look inside it. See [A run failed](troubleshooting.md#a-run-failed).

One kind of failure is different. If a worker stops sending heartbeats for 5 minutes, for example because the laptop slept, Foreman marks the run as failed and releases the issue. It doesn't comment or add the human label.

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
| **Post** | Run page, the issue's newest run once it stops | Saves your message in the run's thread. Nothing resumes. |
| **Continue** | Run page, after a question | Resumes the agent's session with the messages you posted. Asks you to confirm when you posted none. |
| **Continue after my reply** | Run page and dashboard, Needs you | Swaps the human label back to the working label and resumes the agent's session. Use it when the agent asked a question before opening a PR. |
| **Fix conflicts** | Run page and dashboard, Needs you on a merge conflict | Checks the PR with GitHub again, then swaps the human label for the working label and starts a conflict fix at once. See [Fix conflicts](pr-watching.md#fix-conflicts). |
| **Start over** / **Retry** | Run page / dashboard, after a run ends without a PR | Puts the ready label back on the issue so it's queued again. If a slot is free, it starts at once. The new run starts from a fresh worktree. |
| **Dismiss** | Dashboard | Marks the issue as handled outside Foreman and moves it to **Handled**. Nothing is sent to GitHub. **Restore** undoes it. |

## The dashboard

Each repository row shows its track and a **Done today** strip. The strip counts today's finished issues by status.

The **Issues** column groups each issue by its latest run:

- **Needs your reply**, **Failed** and **Ready for review** need you. Their total shows in the stats row and in the browser tab title, like `(2) Foreman`.
- **In progress** and **Watching PRs** are still moving.
- **Handled** shows the last ten dismissed or cancelled issues.

### Tab icon

The browser tab icon shows a dot on every Foreman page, so a pinned tab tells you whether to look. It shows one dot, the first that applies:

1. **Red**: an issue failed.
2. **Amber**: an issue needs your reply.
3. **Green**: a PR is ready for review.
4. **Grey**: a run is at a station, from **Waiting** to **Reviewing**.

With none of these, the icon has no dot. It counts the same issues as the dashboard, so dismissed issues and archived repositories don't count. **Watching PR** doesn't count as busy, since no worker is attached.

The page checks every 10 seconds. Browsers slow down tabs in the background, so a hidden tab may update only about once a minute.

## History

The **History** page lists every issue Foreman has worked on, newest first. It shows totals across all of an issue's runs: runs, turns, cost and tokens. Filter it by repository with the menu at the top.

## Archive

Archiving a repository takes it off the dashboard but keeps its settings and history. Archive it from its settings. You can't archive a repository while it has runs in progress or PRs being watched. Archived repositories are stopped. **Restore** brings one back, still stopped. **Remove** deletes it and its run history from Foreman. Nothing on GitHub is touched either way.
