# Legacy findings go to debt issues, not a ratchet

Status: accepted (2026-11-09)

## Context

The first proposal for the coach in issue #145 had a ratchet: any file an agent touches must be coached until it is clean. Old problems would stay in a baseline until then.

ADR 0006 says only new findings block, which rules out that ratchet. Without it, the coach never makes anyone pay down existing debt. Something else has to.

The ratchet also clashes with how Foreman judges a change. The scope judge rejects work beyond the issue. The size limit turns large changes into drafts (ADR 0005). A run that has to clean every touched file breaks both on most legacy code.

## Decision

- **There is no ratchet on touched files.** Older findings never block a run.
- **A `foreman:debt` command runs weekly for each repository.** It runs the coach on the whole base branch and scores each file as findings × commits in the last 90 days. A messy file that changes often costs every run that touches it. A messy file nobody touches costs little.
- **It opens one issue for the top file, labelled `needs-triage`.** It never uses `ready-for-agent`, so the intake gate stays as it is: a trusted person decides what work agents take.
- **Each debt issue fits one PR.** When a file has too many findings, the issue names only its top smell.
- **At most 2 debt issues are open per repository.** If 2 are open, the command does nothing.
- **Existing baselines stay.** Agents can shrink them but never grow them, the same as the test-integrity check's PHPStan rule. The debt command reads live findings, so it needs no baseline of its own.
- **Snoozed findings are not scored.**

## Consequences

- Debt goes down only as fast as people triage debt issues and agents finish them. At most 2 per repository are in flight.
- Debt issues are normal issues. They go through the same gate, coach, review pass and scope judge as any other run, and the scope judge sees an issue that asks for the cleanup.
- Agents can still leave a touched file worse in ways the coach can't see. That is a job for the review pass.
- The weekly debt score gives each repository a trend line, so we can see whether the coach and the debt issues are working.
