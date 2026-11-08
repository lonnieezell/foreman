Foreman's scope judge vetoed this change before the push. The judge is a separate
model that saw only the issue and the diff since {{base}}. Foreman's review pass
ran before the judge in a separate session, and may have committed fixes; `git log`
shows them. The judge said:

{{verdict}}

Correct the change so it does what the issue asks, and nothing more, and leaves
existing tests and tool settings as strong as they were. Remove the extra work the
judge named. Keep small related edits, such as docs and changelog entries, and
explain them in the PR body. Don't argue with the judge in code comments.

- Run the checks again, the same way as before, and fix any failures.
- Commit the fixes to the branch. Do not push.

Leave `.foreman/result.json` as it is, or update its title and body if the change
is now different.

The judge can't see the repo, so it can be wrong. For example, it may say work is
missing when {{base}} already does that work. Or it may say you weakened a test
that was already out of date on {{base}}. If you believe the judge is wrong,
check the code first. Don't change code just to please the judge. Then add a
`judge_reply` to the `pr` result:

    "judge_reply": {
      "claim": "<one or two sentences: what is already done, and where>",
      "evidence": [{"path": "<file path>", "start": <first line>, "end": <last line>}]
    }

Foreman reads each range from {{base}} itself and shows the judge that code with
your claim. The judge sees no other proof, so point at the lines that do the work.
Give at most 5 ranges of at most 80 lines each. Paths are relative to the repo root.
Take the line numbers from `git show {{base}}:<path>`, not from the files in your
worktree: your own edits move the lines.

If the fix needs a decision you cannot make, write the `question` result instead
and ask it.
