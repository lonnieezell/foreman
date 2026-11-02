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

A round resumes the agent's Claude session in the same worktree. First Foreman merges the base branch into the PR's branch. See [Continuing runs catch up with the base branch](how-foreman-works.md#continuing-runs-catch-up-with-the-base-branch). The agent gets the list of checks that didn't pass and the bot threads that are open. The run page shows rounds under **Attempts** as **Round 1**, **Round 2** and so on.

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

## Auto-merge

With [Auto-merge](repository-settings.md#auto-merge) on, Foreman merges the PR itself at the point where it would end as **PR ready**. That's when checks have passed and no bot threads are open. Foreman runs `gh pr merge`. It doesn't use GitHub's built-in auto-merge, because that waits only for the checks branch protection requires, and it knows nothing about bot threads.

Before it merges, Foreman goes through these steps in order:

1. **Read the setting live.** It uses the repository's setting now, not a copy from when the run started.
2. **Hold while paused.** While Foreman is paused, the run stays in **Watching PR**. It logs "Ready to auto-merge; held while Foreman is paused." once. On the first check after the pause ends, it goes through every step again.
3. **Re-rate the tier.** Foreman rates the PR's final diff again, because the review pass, a scope-judge correction or fix-up rounds can change it. The new tier is saved on the run, and the `risk:tier-N` label on the PR is swapped. If the tier rose and is now above the setting, the run ends as **Needs you** with a PR comment such as "Tier rose from 1 to 3 after fix-up rounds." A PR that was already above the setting from the start isn't merged and ends as **PR ready**. Tier 3 is never merged.
4. **Check for human review activity.** The run ends as **Needs you** if a person has an unresolved review thread, a person's latest verdict is "Changes requested", or the PR is a draft. Plain PR comments outside review threads don't block.
5. **Merge.** Foreman runs `gh pr merge` with the repository's merge method and `--match-head-commit` set to the commit it checked. It never uses `--admin` or `--delete-branch`.

Before merging, Foreman comments "Auto-merged by Foreman: tier N, checks passed, no open threads." After the merge it adds an `auto-merged` label to the PR. It creates the label first if the repository doesn't have it. The run ends as **Merged**, and the log says, for example, "Auto-merged (merge) at abc1234, tier 1."

If `gh pr merge` fails, Foreman doesn't retry. It comments on the PR with the error text, adds the human label to the issue, and ends the run as **Needs you**. Common causes are branch protection that needs an approving review, a base branch that moved, and a merge method the repository turns off.

A merge by a person still ends as **PR ready** with "PR merged." With Watch PRs off, or auto-merge off, nothing here applies.

## Stopping

Click **Stop watching** on the run page to end watching. The run becomes **Cancelled** and the PR stays open.

To turn watching off for new runs, untick **Watch PRs** in the repository's settings. Its runs then end as **PR opened**.
