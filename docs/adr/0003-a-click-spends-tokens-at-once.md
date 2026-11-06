# A person's click spends tokens at once

Status: accepted (2026-11-07)

## Context

Foreman spends tokens in two ways. The poller starts work by itself: new issues and fix-up rounds. A person starts work with a click: **Continue after my reply** and, from issue #130, **Fix conflicts**.

The poller's work waits for holds. It doesn't start while Foreman is paused, a daily spend limit is reached, the repository has no free slot, or the Claude profile is held for usage. **Continue after my reply** already skipped all of these, but nothing recorded that as a rule. **Fix conflicts** needs the same answer, so the rule is written down here.

## Decision

- Work the poller starts by itself waits for pause, the daily budgets, free slots and the usage hold.
- Work a person starts with a click is dispatched at once and skips all four. The person chose to spend the tokens, and a click that silently waits for a hold looks like a click that did nothing.
- **Pause** is skipped too. Pause stops Foreman from acting on its own. It doesn't stop the operator.
- **Continue after my reply** and **Fix conflicts** both follow this rule. A button added later that spends tokens should follow it too, or record why it doesn't.
- Every click still has a lock, so a double click starts one run.

## Consequences

- A click can take spend past a daily budget. The budgets limit what Foreman does unattended, not what the operator asks for.
- A click can run more agents than the repository's concurrency setting, because it doesn't wait for a free slot.
- A click can run while the Claude profile is near its usage limit. If the limit is hit, the run stops as usual and the profile is held.
- Nothing here changes the per-run spend cap (`max_budget_per_run`) or the time limit. They still apply to a run a click started.
