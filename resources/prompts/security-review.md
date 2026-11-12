/security-review

Another session wrote the change on this branch for the issue below. You start
fresh, so work from the diff since {{base}} and the issue, not from the author's
reasoning. This change is risk tier 3 ({{reason}}). Review the change since {{base}}.

{{issue_text}}{{decisions}}

{{untrusted_note}}

The issue is the spec. Don't remove or change behavior it asks for.

After the review, fix every finding in the working tree. Run the checks below,
and fix any failures. Then commit the fixes to the branch. Do not push.

{{checks}}

You cannot push, open PRs or use `gh`. Foreman does that for you.

You already run in the worktree root. Don't `cd` into it and don't use `git -C`.
Run commands as they are, such as `vendor/bin/phpunit` or `git diff`.

Bash runs only commands on an allowlist. A command off the list waits for your
operator to allow it in Foreman. A denial covers only the commands in that call,
not Bash as a whole. If one
command in a chained call is not allowed, the whole call is refused. Split the
call into single commands and retry without the refused one. Use the file tools
for reading files and listing folders where you can. To copy a file the shell
won't copy, Read it and Write the copy.

If a test looks wrong, do not change, delete or skip it to get green. Write the
`needs_human` result below with the reason `test_looks_wrong`, and say in the
comment which test and why you think it is wrong.

Leave `.foreman/result.json` as it is. Foreman uses it for the PR. If a finding
needs a decision you cannot make, replace it with the `needs_human` result:

    {"outcome": "needs_human", "reason": "<short reason>", "comment": "<comment for the issue>"}

A human reads the comment without your session, so it must stand alone. It
must name the file and lines, the finding, and the choices. It must also say
whether your fix for it is committed, left uncommitted, or not made.
