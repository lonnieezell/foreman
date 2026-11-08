# Extra work opens a draft instead of blocking the push

Status: accepted (2026-11-08)

## Context

After a correction round, a second scope veto for added work pushes nothing. The run goes to **Needs you**, and the work stays in a local worktree. ADR 0002 kept this rule.

In practice this blocks good work over small extras. The last four vetoed runs show the range:

- Run 127 added validation rules and edited a changelog and an agent doc. The extras were small and sensible.
- Run 121 changed two existing test assertions. They were most likely stale on the base branch already.
- Run 91 rewrote `EntryStore.php` and pulled code into new files. That was real scope creep.
- Run 85 was vetoed for missing work. ADR 0002 already sends that case to a draft.

The verdict is a set of yes/no flags. Three changelog lines count the same as a whole refactor. And extra work is easy to spot in review, just like missing work.

## Decision

- **A second veto blocks the push only when `weakens_tests` is true.** Any other second veto runs the gate. If the gate passes, Foreman opens a draft with one label per problem: `scope:extra` for added work, `scope:incomplete` for missing work, or both. The footer names the problems, gives the judge's reason, and adds the agent's `judge_reply` if it wrote one.
- **The correction round stays.** Its prompt now says to remove the extra work the judge named, but to keep small related edits, such as docs and changelog entries, and explain them in the PR body. It also says `judge_reply` works for test vetoes, for example to show a test was already out of date on the base branch.
- **The judge's prompt gets a wider list of supporting changes.** Docs, changelog entries and comments about the change, new tests, and small edits the change needs in code it touches are not unrequested work. Only work a reviewer would call a separate change counts: new features, refactors of code the change didn't need to touch, and cleanups in unrelated files.
- **The judge's test rule gets sharper.** Updating an expected value to match behavior the issue asked for is not weakening a test. Changing it to match output the issue didn't ask for is.

## Consequences

- This amends ADR 0002's rule that "a second veto for added work or weaker tests still pushes nothing." Weaker tests still push nothing.
- More runs end as draft PRs, and fewer end in **Needs you**. Real scope creep, like run 91, now reaches review as a draft instead of stopping at the gate. The reviewer trims or rejects it.
- A draft can't be auto-merged, and Foreman never marks a draft ready. So a `scope:extra` PR always gets a human look.
- `weakens_tests` stays a hard stop because a weakened assertion is the easiest problem for a reviewer to miss. The mechanical test-integrity check already catches deleted tests, new skips and config edits. The judge's flag covers changed assertions, which only it can see.
- A looser prompt means the judge may pass some extras outright, with no draft and no label. The wider list is limited to edits that support the requested change.
