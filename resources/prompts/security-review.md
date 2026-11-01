/security-review

This change is risk tier 3 ({{reason}}). Review the change since {{base}}.

After the review, fix every finding in the working tree. Run the checks again,
the same way as before, and fix any failures. Then commit the fixes to the
branch. Do not push.

Leave `.foreman/result.json` as it is. Foreman uses it for the PR. If a finding
needs a decision you cannot make, write the `needs_human` result instead.
