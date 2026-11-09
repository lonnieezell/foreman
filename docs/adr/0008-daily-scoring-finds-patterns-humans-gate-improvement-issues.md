# Daily scoring finds patterns; humans gate improvement issues

Status: accepted (2026-11-09)

## Context

Issue #146 adds a loop that learns from Foreman's own runs. Every run leaves a full transcript in `storage/foreman/logs/run-<id>.jsonl`, but nobody reads them. The same waste repeats: redundant tests, re-reads, repeated full test runs, and searches for facts the docs should have given. We had to decide when runs are judged, what turns into an issue, where issues go, and who decides what gets worked on.

Several facts shaped the choices:

- One run can read millions of cached tokens. Re-reading full transcripts each day would cost about as much as the runs did.
- Foreman has exactly three workers, all on the `runs` queue. Work placed there competes with agent runs.
- `lonnieezell/foreman` is public. Some watched repositories are private.
- The judge reads issue bodies and repository files. Text planted there can steer what it writes.

## Decision

- **Each run is scored once, into a scorecard, and patterns are found from scorecards.** The daily pass reads small scorecards, not transcripts. One bad run is noise. An issue needs a pattern across runs.
- **Scoring runs in one daily scheduled command, `foreman:improve`, not on a queue.** It runs at 03:00 in the background and obeys the usage hold. A scorecard appears the next morning. We accept that delay.
- **Measured facts come from plain code. Only two criteria are judged by a model:** redundant tests and missing context. Each **observation** must carry evidence, or code drops it. More criteria can come later. Removing noisy ones is harder than adding.
- **An improvement is routed to where its fix lives:** Foreman itself, or the target repository.
- **Foreman improvements from private repositories are redacted, and a plain-code leak check runs before any post.** A body that matches the source's paths, its name, or long evidence strings stays held.
- **Improvement issues get `needs-triage` and `foreman:improve`, never `ready-for-agent`.** A human decides what agents work on, the same as debt issues in ADR 0007.
- **Posting is opt-in.** Each repository has a mode, and one global mode covers Foreman improvements. All start on *dashboard only*, where drafts wait as **held improvements**.
- **Dismiss reasons are not fed back into the judge.** Changing the judge prompt stays a human change.

## Consequences

- Each scored run costs one Sonnet call, on the repository's profile and within its usage limits. Real runs always come first.
- Patterns use a 7-day window, so a fix shows its effect within about a week. Drift detection needs 30 days of history. A 30-day backfill on the first run gives it that.
- The judge sees only the tests near the change, so it can miss a duplicate elsewhere. It undercounts rather than invents.
- Redacted issues are vaguer. The linked run IDs only open on the local Foreman.
- At most 2 posted improvement issues are open per target. Improvement goes only as fast as people triage them.
- Dismiss reasons give a false-positive rate per criterion. That tells us when a judged criterion needs tuning, or should go.
