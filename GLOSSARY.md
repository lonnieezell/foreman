# Foreman

Foreman turns labeled GitHub issues into pull requests by running a coding agent, then checks the agent's work before a human sees it.

## Language

**Difficulty**:
How hard Foreman judges an issue to be: `trivial`, `small`, `medium`, `large` or `hard`. In Auto mode it decides the model and effort of the issue's runs, through the **ladder**. It comes from a `foreman:<difficulty>` label, a Haiku classifier, or neither (the fallback step), and is clamped to the repository's floor and ceiling. See ADR 0004.
_Avoid_: complexity, size (size is the diff's line count)

**Ladder**:
The global map from each **difficulty** to a model and effort, called a step. No step may be cheaper than the one below it. See ADR 0004.
_Avoid_: tiers (risk tiers are a different thing)

**Question**:
An agent's stop to ask the operator something it can't decide alone, such as what an ambiguous spec means. It is the `question` outcome. It is answered in Foreman, then the run resumes. Compare a **blocker** (`needs_human`), which needs the operator to change the repo, the issue or the settings, and stays on the issue. See ADR 0001.
_Avoid_: clarification, needs_human (for questions)

**Test-integrity check**:
The guardrail that fails a change when it weakens the tests. It counts test loss across the whole change, so a test moved from one file to another is not a loss.
_Avoid_: test guard, test check
