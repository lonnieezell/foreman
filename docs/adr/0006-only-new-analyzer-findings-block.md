# Only new analyzer findings block

Status: accepted (2026-11-09)

## Context

Issue #145 adds a coach to Foreman's gate. It runs the analyzers a repository has set up, such as PHPStan, ESLint, Ruff and Clippy. It sends the agent refactoring guidance for each finding. Coach findings block like a verify failure, so we had to decide which findings count.

Most files an agent touches already have findings. Three choices came up:

- **Every finding in a touched file.** The agent would have to clean up debt it didn't create. A one-line fix in a 600-line legacy controller would turn into a refactor. The scope judge would veto it as extra work, and the size limit would make it a draft (ADR 0005). The agent would also spend its 3 blocked stops on old problems.
- **Findings on changed lines only.** This is simple, but it misses the case that matters most. Complexity and size findings point at a function's first line. An agent that makes a function worse by adding lines in the middle never touches that line, so the finding slips through.
- **New findings only.** Compare the findings on the branch with those on the base branch, for the changed files.

## Decision

- **A finding blocks only when it is new.** A new finding is in a changed file and has no match on the base branch.
- **Findings match on tool, rule, file, and symbol or message, never line number.** Lines shift with every edit, so a line match would treat moved code as new.
- **The coach runs the analyzers on the base copy of the changed files once per base commit, and caches the result.** The Stop hook's retries reuse it.
- This works like the test-integrity check, which also compares the change against where the branch left its base.

## Consequences

- Making an existing function worse still blocks. Its complexity finding changes, so it has no match on the base branch.
- Older findings in a touched file never block. They are left to the debt issues in ADR 0007.
- Each gate pays for one extra analyzer run on the base, the first time it runs against a base commit.
- Matching without line numbers is fuzzy. If a rename changes a finding's symbol or message, an old finding can look new. The agent can answer with `finding_looks_wrong`, and a human can snooze it.
- A finding that the base already has and the change makes no worse, such as a long function that gets one line longer but stays over the same limit with the same message, does not block. We accept that gap.
