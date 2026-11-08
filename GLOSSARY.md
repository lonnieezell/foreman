# Foreman

Foreman turns labeled GitHub issues into pull requests by running a coding agent, then checks the agent's work before a human sees it.

## Language

**Coach**:
The gate check that runs a repository's own analyzers, such as PHPStan, ESLint, Ruff and Clippy, and sends the agent **guidance** for each **new finding**. New findings block like a verify failure. It only runs tools the repository has set up. See ADR 0006.
_Avoid_: linter, habit hook

**Debt issue**:
An issue that `foreman:debt` opens for the file with the most findings and the most recent commits. It is labelled `needs-triage` and asks for one PR's worth of cleanup. At most 2 are open per repository. See ADR 0007.
_Avoid_: garbage collection ticket, cleanup ticket

**Difficulty**:
How hard Foreman judges an issue to be: `trivial`, `small`, `medium`, `large` or `hard`. In Auto mode it decides the model and effort of the issue's runs, through the **ladder**. It comes from a `foreman:<difficulty>` label, a Haiku classifier, or neither (the fallback step), and is clamped to the repository's floor and ceiling. See ADR 0004.
_Avoid_: complexity, size (size is the diff's line count)

**Finding**:
One problem an analyzer reports: a tool, a rule, a file, a line, a symbol and a message. A **new finding** is one in a changed file with no match on the base branch. Findings match on tool, rule, file and symbol or message, never line number. Only new findings block. See ADR 0006.
_Avoid_: violation, lint error

**Focus label**:
An optional label on a repository that narrows which ready issues the poll takes. When it's set, only issues with both the ready label and the focus label are picked up. It's set from the dashboard only, and doesn't affect runs already in progress. It can't be one of the three workflow labels.
_Avoid_: filter label, priority label

**Guidance**:
A short markdown file for one analyzer rule. It names the smell, the refactoring we want, and what not to do. Foreman ships defaults, and a repository can replace any of them in `.coach/`.
_Avoid_: coaching prompt, hint

**Ladder**:
The global map from each **difficulty** to a model and effort, called a step. No step may be cheaper than the one below it. See ADR 0004.
_Avoid_: tiers (risk tiers are a different thing)

**Question**:
An agent's stop to ask the operator something it can't decide alone, such as what an ambiguous spec means. It is the `question` outcome. It is answered in Foreman, then the run resumes. Compare a **blocker** (`needs_human`), which needs the operator to change the repo, the issue or the settings, and stays on the issue. See ADR 0001.
_Avoid_: clarification, needs_human (for questions)

**Snooze**:
A human's entry in `.coach/snooze` that stops one **finding** from blocking, with a reason and an expiry date no more than 90 days away. Only humans can snooze. The agent can't edit the file.
_Avoid_: ignore, suppress (those are the silencing the coach forbids)

**Test-integrity check**:
The guardrail that fails a change when it weakens the tests. It counts test loss across the whole change, so a test moved from one file to another is not a loss.
_Avoid_: test guard, test check
