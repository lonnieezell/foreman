# Agent questions are answered in Foreman, not on the issue

Status: accepted (2026-11-03). Partly superseded by ADR 0009: a question now waits in the live session for the question window before the run ends.

## Context

Until now, an agent that needed something from a person wrote a `needs_human` result. Foreman posted the agent's comment on the issue. The operator answered on GitHub, then pressed **Continue after my reply**. The issue was the one place where the spec and every decision about it lived.

That makes a short question slow. "Should the export include archived rows?" means leaving Foreman, finding the issue, writing a comment, coming back and pressing Continue.

## Decision

The agent gets a new `question` outcome. A **Question** is a doubt the operator can settle with an answer. A **blocker** needs the operator to change the repo, the issue or the settings. Blockers stay `needs_human` and stay on the issue.

- Questions and answers are kept in Foreman's database, in a `run_messages` table. They are not posted on the issue.
- The issue gets the `needs-human` label and one fixed comment saying the question is being answered in Foreman. No agent text goes in that comment.
- Answers typed in Foreman are trusted operator text. They are not wrapped as untrusted, because Foreman has no login and runs on localhost only. Guardrails, Foreman's gate and the scope judge still apply.
- A resume still reads new issue comments from people with write access, wrapped as untrusted. If they conflict with the operator's answer, the answer wins.
- **Start over** carries every earlier question and answer for the issue into the fresh run's prompt. Without that, a retry would lose decisions that exist nowhere else.

## Consequences

- The issue is no longer the complete record. Some spec decisions live only in Foreman's local SQLite database. Losing the database loses them, and teammates can't see them on GitHub.
- The scope judge sees only the issue and the diff, never the answers. An answer that widens the scope may be vetoed. The fix is to edit the issue.
- Each round of questions is a new run with its own spend cap. There is no cap on rounds, because every round needs the operator to press Continue. The thread shows the total cost across the rounds instead.
- If Foreman is ever exposed beyond localhost, anyone who can reach it could instruct the agent. Adding a login would have to come first.
