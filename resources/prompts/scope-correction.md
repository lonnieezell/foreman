Foreman's scope judge vetoed this change before the push. The judge is a separate
model that saw only the issue and the diff since {{base}}. It said:

{{verdict}}

Correct the change so it stays within the issue and leaves existing tests and tool
settings as strong as they were. Remove work the issue didn't ask for. Don't argue
with the judge in code comments or the PR body.

- Run the checks again, the same way as before, and fix any failures.
- Commit the fixes to the branch. Do not push.

Leave `.foreman/result.json` as it is, or update its title and body if the change
is now different. If you believe the judge is wrong, or the fix needs a decision you
cannot make, write the `needs_human` result instead and explain why.
