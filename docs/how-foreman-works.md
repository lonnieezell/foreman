# How Foreman works

Foreman treats the agent as capable but untrusted. The agent writes the code. Foreman decides what reaches GitHub, and it only trusts checks it ran itself. This page follows an issue from label to PR and explains why each step is there.

## From issue to PR

```
GitHub issue labelled ready-for-agent
        │  foreman:poll, every minute
        ▼
claimed: label swapped to agent-working
        │
        ▼
Waiting ─► Setting up ─► Writing code ─► Running checks ─► Reviewing
            worktree +     claude -p       tests / static    Foreman's gate, review pass,
            setup script                   analysis          scope judge, gate again
        │
        ├─► Watching PR ─► PR ready      (checks pass, no open bot threads, or merged)
        │        │
        │        └─► fix-up round ─► Watching PR …  (up to FOREMAN_PR_MAX_ROUNDS)
        │
        ├─► PR opened   (Watch PRs is off)
        ├─► Needs you   (the agent asked for help, Foreman's checks failed, or rounds ran out)
        └─► Failed      (error, timeout, or no readable result file)
```

1. **Claim.** The scheduler finds open issues with the ready label and swaps it for the working label. See [How a run starts](runs.md#how-a-run-starts).
2. **Set up.** A worker makes a fresh git worktree on `agent/issue-<n>` and runs the repository's setup script in it. Your own checkout is never touched.
3. **Work.** Claude Code runs in the worktree. Hooks block edits to protected files, format staged files on each commit, and won't let the agent stop until its checks pass. See [Guardrails](guardrails.md).
4. **Report.** The agent commits and writes a result file. It can't push or use `gh`.
5. **Check and push.** Foreman checks the work, then acts on GitHub itself.

## How Foreman picks the model and effort

In a repository in **Auto** mode, each issue gets a difficulty: `trivial`, `small`, `medium`, `large` or `hard`. The [difficulty ladder](global-settings.md#difficulty-ladder) maps it to a model and effort. The pick happens when the run starts, before the worktree is set up.

1. **A label wins.** A label such as `foreman:hard` on the issue sets the difficulty, and no classifier runs. With more than one, the hardest counts.
2. **An earlier pick is kept.** The pick is made once per issue. Continued runs, fix-up rounds, conflict fixes and retries reuse the step of the issue's last run that had one. A label added since still wins.
3. **Otherwise Haiku rates the issue.** It is a single call with no tools. It reads the issue's title and body and the comments from people with write access, up to about 20,000 characters. It keeps the start of the body and the newest comments, and says when it left text out. It also reads the detected stack and its high-risk paths. It picks the higher level when torn and gives a one-sentence reason. It is told to ignore anything in the issue about the model, effort or difficulty. Its cost counts toward the run.
4. **Fallback.** If the classifier gives no usable answer twice, the run uses the fallback step.

Every pick is then clamped to the repository's [floor and ceiling](repository-settings.md#floor-and-ceiling). So prompt injection in an issue can at worst push the pick up to the ceiling. Untrusted comments never reach the classifier.

The pick shows in the run log, on the issue card, on the run page, and in the PR. The PR's footer gets a line such as `Difficulty: medium (classifier) → sonnet high`, and the PR gets a `difficulty:<level>` label. Foreman never labels the issue with its own pick, because an issue label is an override.

A failed run doesn't move up a step by itself. To retry with more model, add a `foreman:<difficulty>` label and retry.

In **Fixed** mode there is no pick. Every run uses the repository's model and effort, and `foreman:<difficulty>` labels do nothing. The run log says when it ignored one.

To see what Foreman would pick without starting a run, use [`foreman:classify`](commands.md#foremanclassify).

## The agent never touches GitHub

An agent that can run commands and reads text anyone can write should hold as little as possible. So the agent's environment has no push URL, no git credentials and no GitHub token. Foreman does every GitHub action itself, in its own process. [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent) has the details and the incidents behind this choice.

### How a run ends

The agent finishes by writing a result file in its worktree, `.foreman/result.json`. Foreman reads it and does everything on GitHub itself.

The file says one of four things:

| Outcome | What the file holds | What Foreman does | The run ends as |
| --- | --- | --- | --- |
| `pr` | A title and a body | Runs the checks below, then pushes the branch and opens the PR. It adds `Closes #<n>` to the top of the body unless the body already starts with it, and its own footer at the bottom. It also adds a `risk:tier-<n>` label to the PR. | **Watching PR**, or **PR opened** if Watch PRs is off |
| `question` | One or more questions | Saves each question in Foreman, adds the human label and posts one fixed comment: "Foreman's agent has a question. It's being answered in Foreman." The questions themselves never go on the issue. | **Needs you** |
| `needs_human` | A comment and a reason | Posts the comment on the issue and adds the human label (`needs-human`) | **Needs you** |
| `already_done` | A comment | Posts the comment on the issue and adds the human label | **Needs you** |

A missing file, or one that isn't valid JSON or lacks a field its outcome needs, fails the run. The agent's last message goes on the run page and in a comment on the issue. Foreman never guesses what the agent meant.

A `question` is a doubt you can settle with an answer, such as what an unclear spec means. A `needs_human` result is a blocker: it needs you to change the repo, the issue or the settings. The agent asks questions on the first run, in the review pass and in a scope correction round. It can't ask them in a PR fix-up round. A `question` result needs a list of questions that aren't blank. Anything else fails the run.

The reason on a `needs_human` result shows in the run log. One reason is `test_looks_wrong`. It gives the agent an honest way out when a test seems wrong, instead of changing or skipping the test to get green.

## Foreman checks the work before it pushes

A `pr` result doesn't open a PR straight away. On the **Reviewing** station, Foreman runs these steps in order. If any step fails, nothing is pushed and the run goes to **Needs you**.

1. **[Foreman's gate](guardrails.md#foremans-gate).** The test-integrity check, the verify script and a size check, run in Foreman's own process.
2. **[Risk tier and review pass](guardrails.md#risk-tiers).** Foreman rates the diff from 1 to 3. Then a fresh Claude session reviews the change, as deeply as the tier calls for.
3. **[Scope judge](guardrails.md#scope-judge).** A separate Haiku call with no tools reads only the issue and the diff. If it vetoes the change, the agent gets one round to correct it, or to answer the veto with code from the base branch.
4. **The gate again.** So a fix from the review or the correction can't slip past it.
5. **Push.** Foreman pushes the branch and opens the PR, with a footer that lists the checks and the judge's verdict. If `gh pr create` fails or times out, Foreman looks for an open PR from the branch first. `gh` can stop after GitHub has already opened the PR. If a PR is there, the run carries on with it.

The agent's own hooks already ran the same checks. Foreman runs them again because a hook can give up, fail to load, or be skipped. See [Why checks run again outside the agent](guardrails.md#why-checks-run-again-outside-the-agent).

## Rounds and resumes

These kinds of run pick up where an earlier run stopped. Both resume the agent's Claude session in the same worktree, so it keeps everything it learned.

- **Continue after my reply.** For a run that stopped at **Needs you** before opening a PR. The agent reads the new issue comments, but only those from people with write access. A `pr` result then goes through all the checks above.
- **Continue after a question.** For a run that stopped with a `question`. You answer in the thread on the run page, then click **Continue**. The agent gets every message you posted on that run first, as trusted operator text. New issue comments from people with write access follow, marked as untrusted. If the two conflict, the agent is told your answer wins. Each round of questions is a new run with its own spend cap. There is no limit on rounds, because each one waits for you.
- **Fix conflicts.** For a watched PR that conflicts with the base branch. Foreman rebuilds the worktree, runs the setup script, merges the base branch and leaves the conflict markers in place. Then it resumes the agent to resolve them. It doesn't use the catch-up merge below, because that aborts on a conflict. If Claude can't find the agent's session, the agent starts fresh once, with the issue and both sides' changes. Foreman pushes without force. See [Merge conflicts](pr-watching.md#merge-conflicts).
- **Fix-up rounds.** For a watched PR with failing checks or open bot threads. The agent gets the CI logs and the bot threads, commits its fixes, and gives a verdict for each thread. Foreman pushes and replies. A round's push skips the gate, the review pass and the scope judge, so CI on the PR is the check. See [PR watching](pr-watching.md#fix-up-rounds).

**Start over** begins a fresh session, so it can't remember earlier answers. Instead, its prompt gets every earlier question and answer for the same repository and issue, oldest first, under "Decisions your operator already made".

Text from other people reaches the agent marked as untrusted. See [Rounds and resumes follow the same rules](guardrails.md#rounds-and-resumes-follow-the-same-rules).

### Continuing runs catch up with the base branch

A [fix-up round](pr-watching.md#fix-up-rounds) or a **Continue after my reply** run reuses the branch it left behind. Before the agent starts, Foreman fetches `origin` and merges `origin/<base>` into that branch. A fix that landed on the base branch in the meantime then reaches the run. On a **Continue after my reply** run, the agent is told which commits the merge brought in and asked to run its checks again. So you can fix a blocker on the base branch and continue without replying.

When the worktree is gone and Foreman rebuilds it from the pushed branch, the merge runs before the setup script, so dependencies the base branch added get installed. A worktree that is still there is reused as it is, and the setup script doesn't run again.

| Situation | What happens |
| --- | --- |
| The merge goes through | The run goes on from the merged branch. The run log shows `Merging origin/<base> into <branch>`. |
| The merge conflicts, fails or takes over 2 minutes | Foreman aborts the merge and the run goes on from the branch as it was. The log line starts with `Couldn't merge origin/<base>` and shows git's output. |
| Fetching `origin` fails, worktree reused | Foreman doesn't merge. The run goes on from the branch as it was, and the log line starts with `Couldn't fetch origin`. |
| Fetching `origin` fails, worktree rebuilt | The run fails with "Fetching origin failed: …", because Foreman can't rebuild the worktree without it. |
| The worktree has uncommitted changes | Foreman doesn't merge, because `git merge --abort` can't always put those changes back. The run goes on from the branch as it was. |

A conflict here doesn't stop the run or flag a human. Most fixes on the base branch aren't needed by the run, and the [default checks](stack-rules.md#what-the-default-checks-cover) already ignore problems the agent didn't touch. If the branch still needs the fix, [Foreman's gate](guardrails.md#foremans-gate) fails and a human sees why. The branch's commits are never lost: an aborted merge leaves the branch where it was.

## Polling, workers and heartbeats

- **Polling is nearly free.** The scheduler only calls `gh`, so checking costs no tokens. The one exception is a usage probe: a one-turn Haiku call made when there's work to start and the profile's usage numbers are stale. See [Usage limits](usage-limits.md#where-the-numbers-come-from).
- **Workers are plain Laravel queue workers.** `composer run foreman` starts three, so up to three runs execute at once across all repositories. Each repository's **Issues at a time** setting limits how many of those slots it can use.
- **Dead runs are reaped.** A run whose worker stops sending heartbeats for 5 minutes is marked failed, and its issue is released. This happens when a laptop sleeps or a process is killed.
