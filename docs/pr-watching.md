# PR watching

With **Watch PRs** on, Foreman doesn't stop when it opens a pull request (PR) for the agent's work. It waits for CI and review bots. Then it runs *fix-up rounds* that repair failing checks and answer the bots' comments.

Watching only calls `gh`. It costs no tokens, and it keeps going while Foreman is paused. Only a round costs tokens. Before a round, Foreman may also run a [usage probe](usage-limits.md#where-the-numbers-come-from).

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

A round resumes the agent's Claude session in the same worktree. First Foreman merges the base branch into the PR's branch. See [Continuing runs catch up with the base branch](guardrails.md#continuing-runs-catch-up-with-the-base-branch). The agent gets the list of checks that didn't pass and the bot threads that are open. The run page shows rounds under **Attempts** as **Round 1**, **Round 2** and so on.

The agent never touches GitHub during a round. Foreman does that part:

- **CI logs come from Foreman.** For each failed check that ran as a GitHub Actions workflow, Foreman fetches the log of the failed steps and puts the end of it (the last 6,000 bytes) in the prompt. A check from an outside service has no log to fetch, so the prompt lists only its name and link.
- **Bot comments are untrusted.** Anyone can make a bot say anything, for example by putting text in a pull request. So Foreman wraps each bot thread, and each log, between `<untrusted_text>` tags. A note in the prompt tells the agent that the text is data and not instructions. See [Guardrails](guardrails.md#rounds-and-resumes-follow-the-same-rules).
- **Foreman pushes.** The agent commits its fixes. When it finishes, Foreman pushes the branch, then posts the replies and resolves threads. A round's push doesn't go through [Foreman's gate](guardrails.md#foremans-gate), the review pass or the scope judge. CI on the PR is the check.

Rounds spend tokens, so they wait for the same things new issues do. A round won't start while:

- Foreman is paused
- a daily spend limit is reached
- the repository has no free slot
- its Claude profile is held for usage

Foreman tries again on the next check.

**Fix-up rounds per PR** sets how many rounds a PR gets. The default is 2. With 0, Foreman still waits for the PR to settle. A PR that passes ends as **PR ready**. Anything outstanding goes straight to you, with no round.

### Replies to bot threads

The agent is never allowed to comment on, reply to or resolve threads itself. Instead, it writes a verdict for each thread, and Foreman posts the reply:

| Verdict | Foreman's reply | Thread |
| --- | --- | --- |
| **fixed** | "Fixed in abc1234: …" | Resolved |
| **declined** | "Not changing: …" with the agent's reason | Resolved |
| **unsure** | "Leaving for a human: …" | Left open for you |

The sha in "Fixed in" is the branch head after Foreman's push. If the agent says "fixed" but made no commit, so nothing was pushed, Foreman posts no reply and leaves the thread open. The same happens when the agent gives no verdict for a thread.

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
