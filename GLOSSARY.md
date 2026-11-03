# Foreman

Foreman turns labeled GitHub issues into pull requests by running a coding agent, then checks the agent's work before a human sees it.

## Language

**Question**:
An agent's stop to ask the operator something it can't decide alone, such as what an ambiguous spec means. It is the `question` outcome. It is answered in Foreman, then the run resumes. Compare a **blocker** (`needs_human`), which needs the operator to change the repo, the issue or the settings, and stays on the issue. See ADR 0001.
_Avoid_: clarification, needs_human (for questions)

**Test-integrity check**:
The guardrail that fails a change when it weakens the tests. It counts test loss across the whole change, so a test moved from one file to another is not a loss.
_Avoid_: test guard, test check
