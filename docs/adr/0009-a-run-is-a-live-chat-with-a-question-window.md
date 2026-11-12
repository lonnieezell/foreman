# A run is a live chat, and a question gets a window

Status: accepted (2026-11-12). Supersedes the part of ADR 0001 that says every question ends the run.

## Context

ADR 0001 moved questions into Foreman, but each round still ended the run. The agent wrote a `question` result and exited. The operator answered and pressed Continue. A new process started with `--resume` and a prompt that held the answer. That is slow, and the answer arrives inside a summary, not as a reply in the middle of the work. The operator also couldn't step in while a run was going.

We checked how `claude` 2.1 behaves with `--input-format stream-json`:

- After a `result` event, the CLI waits for another message on stdin. It exits, with code 0, only when stdin closes.
- A message sent mid-turn doesn't interrupt. The CLI holds it until the current tool call ends, then hands it to the agent in the same turn.
- `total_cost_usd` and `--max-budget-usd` count the whole session. `num_turns` and `usage` in each `result` count that turn alone.
- A `set_model` control request changes the model mid-session. We don't use it yet.

## Decision

Every agent pass is a live session. The prompt goes in as the first stream-json message, and stdin stays open.

- **Operator messages go in live.** While the agent runs, the run page takes messages. Foreman saves each one in `run_messages` and writes it to stdin within a few seconds. It marks the message with `sent_at`. `--replay-user-messages` echoes it back, and Foreman logs it in order then.
- **A turn with nothing more ends the session.** When a turn ends and no message is waiting, Foreman closes stdin. A run nobody joins ends exactly as before.
- **A message after a turn starts a new one.** Foreman deletes the result file first and tells the agent to write it again, so an old result can't end the new turn.
- **A question gets a window.** When a turn ends on a `question` result, Foreman keeps the questions in the thread and holds the session open for the repository's **question window** (default 10 minutes). An answer inside it goes into the same session. Foreman clears the result file first, so the agent writes a fresh one. With no answer in time, Foreman closes stdin and the run takes the ADR 0001 path: Needs you, then Continue.
- **The window is a repository setting,** from 0 to 120 minutes. 0 ends the session at once, as before.
- **Conflict fixes get no window.** Their questions explain a failure, so they end the run as before.
- **A resume repeats only what the session never got.** Messages with `sent_at` are already in the conversation, so the resume prompt leaves them out, along with the questions asked before the last of them. **Start over** still gets every question and answer.

## Consequences

- A waiting run holds one of the three workers. `max_minutes` keeps counting, and the window keeps the wait bounded. The window never runs past the run's time limit, so a question asked near the limit stops the run on the question instead of failing it. Once stdin is closed, the CLI gets one extra minute to exit.
- Every message spends tokens (ADR 0003). `--max-budget-usd` still caps the whole session.
- Operator messages are trusted text, as in ADR 0001. That relies on Foreman running on localhost with no login. If Foreman is ever exposed, a login must come first.
- Guardrails, the gate and the scope judge still apply. A message can't get around them, because they run outside the session.
- A message posted after Foreman closes stdin isn't sent, and the page stops offering to send one then. It stays on the run. If the run starts another agent pass, such as the review pass, that pass gets it. Otherwise it reaches the agent on Continue, and on a run that goes on to open a PR, nobody acts on it.
- We don't pass `--include-partial-messages`. The run page polls every 2 seconds and shows whole log lines, so the partial text would only grow the raw log. Add it when a view streams text as it's written.
- We haven't checked whether `--max-turns` counts per message or for the whole session.
