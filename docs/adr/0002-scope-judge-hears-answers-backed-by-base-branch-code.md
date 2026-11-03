# The scope judge hears the agent's answer, backed by code Foreman reads

Status: accepted (2026-11-04)

## Context

The scope judge saw only the issue and the diff. That kept it independent of the agent's reasoning. It also meant a correction round could never change its mind when the judge was simply wrong.

Run 85 (lonnieezell/Bonfire2#588) shows the problem. The issue asked for two fixes, and the base branch already had one. The diff showed only the other fix, so the judge said work was missing. The agent checked the code, found the fix already there, and explained this. The judge ran again with the same inputs and vetoed again. Nothing was pushed, and the good half of the work sat in a local worktree.

## Decision

- After a correction round, the agent may add a `judge_reply` to its `pr` result. It holds a short claim and up to five line ranges of at most 80 lines each.
- Foreman reads those ranges from the base branch itself, with `git show`. The judge gets the claim, marked as untrusted, and the code Foreman read. It never gets the agent's own copy of the code. Its prompt says to trust the code, not the claim.
- Only the judge's second call gets the answer. The first call still sees only the issue and the diff.
- The verdict splits `in_scope` into `adds_unrequested` and `misses_requested`. `weakens_tests` stays.
- A second veto only for missing work runs the gate. If the gate passes, Foreman opens a draft labelled `scope:incomplete`, with the judge's reason and the agent's answer in the footer. A second veto for added work or weaker tests still pushes nothing.
- The hand-off comment after a second veto includes the agent's answer, or its PR body when it gave none.

## Consequences

- This amends ADR 0001's statement that the judge sees only the issue and the diff. The operator's answers to agent questions still never reach the judge.
- The agent now has a channel to the judge. Its claim alone should not sway the judge, but Haiku may still be swayed by confident text. The base-branch code is the guard: a claim the code doesn't back up should fail.
- Excerpts come only from the base branch. An answer can't point at the agent's own changes, because those are already in the diff.
- An agent could skip part of an issue and still get a draft PR. The draft status and the `scope:incomplete` label keep that visible. A draft can't be auto-merged.
