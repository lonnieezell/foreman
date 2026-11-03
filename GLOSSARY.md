# Foreman

Foreman turns labeled GitHub issues into pull requests by running a coding agent, then checks the agent's work before a human sees it.

## Language

**Test-integrity check**:
The guardrail that fails a change when it weakens the tests. It counts test loss across the whole change, so a test moved from one file to another is not a loss.
_Avoid_: test guard, test check
