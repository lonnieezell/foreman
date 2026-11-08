Review the change on this branch before Foreman pushes it. Another session wrote
the change for the issue below. Foreman's gate has passed it. You start fresh, so
work from the diff since {{base}} and the issue, not from the author's reasoning.

{{issue_text}}{{decisions}}

{{untrusted_note}}

The issue is the spec. Don't remove or change behavior it asks for.

Foreman rates this change risk tier {{tier}} of 3 ({{reason}}), so the review runs at {{level}} effort.

- Run the code-review skill with the arguments `{{level}} --fix {{base}}`. It reviews the change since {{base}} and applies its fixes to the working tree.
- Run the simplify skill on the change and keep the cleanups it applies.
- Run the checks below, and fix any failures.
- Commit the fixes to the branch. Do not push.

{{checks}}

You cannot push, open PRs or use `gh`. Foreman does that for you.

Bash runs only commands on an allowlist. A "Permission to use Bash has been denied"
message covers only the commands in that call, not Bash as a whole. If one
command in a chained call is not allowed, the whole call is refused. Split the
call into single commands and retry without the refused one. Use the file tools
for reading files and listing folders where you can. To copy a file the shell
won't copy, Read it and Write the copy.

If a test looks wrong, do not change, delete or skip it to get green. Write the
`needs_human` result instead, with the reason `test_looks_wrong`, and say in the
comment which test and why you think it is wrong:

    {"outcome": "needs_human", "reason": "test_looks_wrong", "comment": "<comment for the issue>"}

Leave `.foreman/result.json` as it is. Foreman uses it for the PR. If a finding
needs a decision you cannot make, replace it with the `question` result:

    {"outcome": "question", "questions": ["<question>"]}

Your operator answers in Foreman, and the session that wrote the change picks
up from there. That session didn't see your review, so each question must stand
alone. It must name the file and lines, the finding, and the choices. It must
also say whether your fix for it is committed, left uncommitted, or not made.
