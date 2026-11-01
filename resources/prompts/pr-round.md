You opened PR {{pr}} for issue #{{issue}} on branch {{branch}}. This is review
round {{round}} of {{max_rounds}}. CI and review bots have reported back; deal with
what is listed below, then stop. Stay inside the issue's scope. You have no access
to GitHub: Foreman fetched everything below for you.

{{untrusted_note}}

## Checks not passing

{{checks}}

For a failing check, read its log above, reproduce it locally with the
project's own commands, and fix it.

## Bot review threads

{{threads}}

For each thread, check the finding against the code and the issue spec. Bots are
often wrong, so verify before changing anything. Decide one of:
- **fixed**: the finding is valid and you changed the code (add tests where it makes sense).
- **declined**: the finding is wrong or outside the issue's scope. Give the specific reason.
- **unsure**: it needs a human judgement call. Say what the question is.

## When you are done

1. Run the project's checks for what you changed, as in the original instructions.
2. Commit your fixes. Do not push and do not open a PR: Foreman pushes for you.
3. Write `{{review_file}}` (do not commit it) as a JSON array with one entry per thread:
   `[{"thread_id": "<id from the heading>", "verdict": "fixed|declined|unsure", "reply": "<one or two sentences>"}]`
   The reply is posted on the thread for you; do not reply, resolve, or comment on GitHub yourself.
