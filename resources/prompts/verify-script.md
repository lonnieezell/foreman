Work out the verify script Foreman should run for this project, and prove it works.

How Foreman uses the script:
- The agent can't stop until this script exits 0. Foreman runs it from the worktree
  root with `bash -ec '<script>'`, so it stops at the first failing line.
- When it fails, the agent gets the last part of its output and has to fix the cause.
  After 3 failed stops Foreman lets the agent stop and flags the run for a human.
- The worktree is created from `origin/{{base}}`, then the setup script runs, so
  dependencies are installed before the verify script runs.

The current script, as a starting point:

```bash
{{verify_script}}
```

1. Read the project to see which checks it expects before a change can merge: CI
   workflows, composer.json and package.json scripts, CLAUDE.md or AGENTS.md. Match
   what CI runs, in the same order: formatter check, static analysis or lint, type
   check, then the tests.
2. Use check-only forms (`pint --test`, `prettier --check`, `cargo fmt --check`), so
   the script never changes files. Prefer the project's own composer or package.json
   scripts.
3. Don't add checks CI doesn't run, and don't leave out checks CI does run.
4. Verify it: run `git fetch origin`, then create a detached worktree from
   `origin/{{base}}` in a temporary directory, run the project's setup, then run the
   script there exactly as Foreman does. Fix the script and try again, at most 3
   attempts.
5. Don't commit, push, or change tracked files. Always remove the temporary
   worktree, even if something fails.

Reply with the final script alone in one ```bash block, ready to paste into Foreman.
Under it, note briefly:
- which CI step each line matches
- how long the script took
- anything CI runs that the script leaves out, and why
