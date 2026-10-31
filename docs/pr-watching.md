# PR watching

With **Watch PRs** on, Foreman doesn't stop when the agent opens a pull request (PR). It waits for CI and review bots. Then it runs *fix-up rounds* that repair failing checks and answer the bots' comments.

Watching only calls `gh`. It costs no tokens, and it keeps going while Foreman is paused.

## What Foreman looks at

- **Checks**: the CI checks and commit statuses on the PR's latest commit. Each one is pending, passed or failed.
- **Bot threads**: unresolved review threads started by a GitHub bot account, such as a code-review app. Threads started by people are ignored. Those are yours to answer.
- **Bot activity**: when a bot last commented on or reviewed the PR.

## When Foreman decides

Foreman waits until the PR has *settled*. That means one of these:

- No check is pending, and the bots have been quiet for **Review bots quiet for** (5 minutes by default).
- No check is pending, and no bot has ever posted on the PR. Then it only waits `FOREMAN_PR_FIRST_LOOK_MINUTES` (1 minute by default).
- **Start a round anyway after** (30 minutes by default) has passed since the push. A check that's still pending then counts as not passed.

Some changes don't wait for the PR to settle:

| PR state | Result |
| --- | --- |
| Merged | **PR ready** |
| Closed without merging | **Cancelled** |
| Someone else pushed to the branch | **Needs you**. Foreman leaves the PR to you. |

Once it has settled:

| PR state | Result |
| --- | --- |
| All checks pass and no bot threads are open | **PR ready** |
| Failing checks or open bot threads | A fix-up round |
| Still outstanding after the last round | A summary comment on the PR, the human label on the issue, and **Needs you** |

## Fix-up rounds

A round resumes the agent's Claude session in the same worktree. It gets the list of checks that didn't pass and the bot threads that are open. The run page shows rounds under **Attempts** as **Round 1**, **Round 2** and so on.

Rounds spend tokens, so they wait for the same things new issues do. A round won't start while:

- Foreman is paused
- a daily spend limit is reached
- the repository has no free slot
- its Claude profile is held for usage

Foreman tries again on the next check.

**Fix-up rounds per PR** sets how many rounds a PR gets. The default is 2. Set it to 0 to hand every unsettled PR to you straight away.

### Replies to bot threads

The agent is never allowed to comment on, reply to or resolve threads itself. Instead, it writes a verdict for each thread, and Foreman posts the reply:

| Verdict | Foreman's reply | Thread |
| --- | --- | --- |
| **fixed** | "Fixed in `abc1234`: …" | Resolved |
| **declined** | "Not changing: …" with the agent's reason | Resolved |
| **unsure** | "Leaving for a human: …" | Left open for you |

If the agent says "fixed" but didn't push anything, Foreman posts no reply and leaves the thread open. The same happens when the agent gives no verdict for a thread.

After a round, Foreman watches the PR again from the new commit.

## When rounds run out

If checks still fail or bot threads are still open after the last round, Foreman:

1. comments on the PR with a list of what's outstanding
2. adds the human label to the issue
3. ends the run as **Needs you**

The PR is yours from there.

## Stopping

Click **Stop watching** on the run page to end watching. The run becomes **Cancelled** and the PR stays open.

To turn watching off for new runs, untick **Watch PRs** in the repository's settings. Its runs then end as **PR opened**.
