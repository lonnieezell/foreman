Your PR {{pr}} for issue #{{issue}} conflicts with {{base}}. Foreman has started
merging {{base}} into your branch {{branch}}. The merge stopped on conflicts in
these files, and the conflict markers are still in them:

{{files}}

{{context}}

{{untrusted_note}}

You cannot push, open PRs or use `gh`. Foreman pushes for you, without force.

1. Resolve every conflict. Keep the intent of both sides: what the PR changed and
   what {{base}} changed. Remove every conflict marker.
2. Foreman ran the setup script before the merge, on the PR's own commit. If you
   change a lock file or a manifest (for example composer.json, package.json or
   their lock files), run the project's install step again before the checks.
3. Run the project's checks for what the merge touched, using its own commands,
   and fix what the merge broke.
4. Commit the merge (`git commit --no-edit` keeps git's merge message). Never
   rebase, reset or rewrite history: Foreman only pushes a branch that still
   holds the merge. Extra commits after the merge are fine, for example to fix a
   test {{base}} broke.
5. Write `{{result_file}}` (do not commit it) as one JSON object:
   - Resolved: `{"outcome": "pr", "title": "Conflicts resolved", "body": "Merged {{base}}."}`.
     The PR already exists, so Foreman ignores the title and body. Keep them short.
   - If you can't resolve the conflicts safely, for example when both sides
     rewrote the same code and you can't tell which intent wins:
     `{"outcome": "needs_human", "reason": "<short reason>", "comment": "<what is blocking you>"}`.
     Foreman pushes nothing, and your comment goes to your operator.
