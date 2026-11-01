Foreman's gate passed. Before Foreman pushes, review your change.

Foreman rates this change risk tier {{tier}} of 3 ({{reason}}), so the review runs at {{level}} effort.

- Run the code-review skill with the arguments `{{level}} --fix {{base}}`. It reviews the change since {{base}} and applies its fixes to the working tree.
{{simplify}}- Run the checks again, the same way as before, and fix any failures.
- Commit the fixes to the branch. Do not push.

Leave `.foreman/result.json` as it is. Foreman uses it for the PR. If a finding
needs a decision you cannot make, write the `needs_human` result instead.
