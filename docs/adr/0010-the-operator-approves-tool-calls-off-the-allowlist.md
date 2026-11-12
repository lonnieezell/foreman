# The operator approves tool calls off the allowlist

Status: accepted (2026-11-12).

## Context

Runs used `--permission-mode dontAsk`. A tool call that wasn't on the repository's allowed tools was refused, and nobody was asked. The agent then worked around it, gave up, or ended on `needs_human`. The operator found out after the run. A widened default list (#177) covers the common cases. A long tail stays: repo-specific scripts such as `./vault test`, one-off tools, and odd shell forms.

Issue #179 proposed an MCP server that Claude would call for each prompt. ADR 0009 made every run a live session, with Foreman writing stream-json to the agent's stdin. That gives a simpler route. We checked `claude` 2.1.322:

- `--permission-prompts host` alone sends nothing to the host. Anything that would prompt is still denied.
- With `--permission-prompt-tool stdio` as well, the CLI writes a `control_request` with subtype `can_use_tool` to stdout. It holds the tool name, the input and a `request_id`. It then waits for a `control_response` on stdin.
- `{"behavior":"allow","updatedInput":…}` runs the call. `{"behavior":"deny","message":…}` refuses it, and the agent sees the message as the tool's error.
- An allow can carry `updatedPermissions` with an `addRules` entry for the `session`. Later calls that match then run without asking.
- `dontAsk` denies before the host is asked. `manual` asks. (`default` is no longer a listed mode.)
- Read-only commands, such as `echo`, run without a prompt in `manual` mode.

## Decision

Foreman answers permission prompts itself, over the live session's stdin. There is no MCP server.

- **The flags.** `--permission-mode manual --permission-prompts host --permission-prompt-tool stdio`.
- **A request is saved and waits.** Each `can_use_tool` request becomes a row in `permission_requests`, with an `expires_at` from the repository's **approval timeout** (default 3 minutes, 0 to 60). The run log says what was asked.
- **The operator answers on the run page.** Allow once, Always allow, or Deny. Always allow adds a pattern to the repository's allowed tools. Foreman suggests one from the call's first word, such as `Bash(./vault:*)`, and the operator can edit it. The answer also adds the pattern for the rest of the session.
- **The live loop sends the answer.** Every 2 seconds it denies the requests past `expires_at`, then writes each answer the session hasn't had. It logs what was chosen.
- **The first answer wins.** Deciding is a conditional update on an undecided row. A click after the timeout changes nothing, and the page says so.
- **The dashboard flags a waiting run** with a **needs approval** badge.

## Consequences

- A run nobody watches behaves almost as before. A call off the list is denied after the timeout, not at once, so each one costs up to 3 minutes of the run's time. 0 restores the old behavior.
- The denial message is Foreman's own, not the CLI's `dontAsk` text. The agent prompts no longer quote the old text.
- Guardrails still apply. `foreman:guard` runs as a `PreToolUse` hook, before the permission check, so an approval can't let through `git push`, `gh` or `--no-verify`.
- A waiting run holds a `runs` worker, and `max_minutes` keeps counting.
- Always allow trusts the operator's pattern. A broad one, such as `Bash`, opens every command to every later run of the repository.
- We don't handle `control_cancel_request`. A request the CLI drops stays on the page until it times out. The answer then goes to a request the CLI no longer waits for.
- We haven't checked how tools such as `AskUserQuestion` behave in `manual` mode under `-p`. If they come through as requests, the operator sees them like any other tool.
