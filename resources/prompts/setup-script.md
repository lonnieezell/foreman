Work out the setup script Foreman should run for this project, and prove it works.

How Foreman uses the script:
- Each issue gets a fresh git worktree, created from `origin/{{base}}`. Nothing
  gitignored exists there: no `.env`, no `vendor`, no `node_modules`, no built assets.
- Foreman runs the script from the worktree root with `bash -ec '<script>'`, so it
  stops at the first failing line. It can't prompt for input and has 15 minutes.
- Afterwards an agent writes code and runs the project's checks (tests, static
  analysis, lint), using only these allowed tools:
  {{allowed_tools}}

The current script, as a starting point:

```bash
{{setup_script}}
```

1. Read the project to see what a fresh checkout needs: lock files, `.env.example`,
   composer.json and package.json scripts, test config, CI workflows, CLAUDE.md or
   AGENTS.md.
2. Prefer `.env.example`. If the checks need a gitignored file (real API keys, a
   licence file, `auth.json`), copy it from the main checkout, which the script can
   find with `"$(git rev-parse --path-format=absolute --git-common-dir)/.."`.
   Never write secret values into the script: Foreman stores it and shows it on screen.
3. Verify it: run `git fetch origin`, then create a detached worktree from
   `origin/{{base}}` in a temporary directory. Run the script there exactly as
   Foreman does, then run the project's own checks (prefer its composer or
   package.json scripts). Fix the script and try again, at most 3 attempts.
4. Don't commit, push, or change tracked files. Always remove the temporary
   worktree, even if something fails.

Reply with the final script alone in one ```bash block, ready to paste into Foreman.
Under it, note briefly:
- any line that isn't obvious, and why it's there
- each file copied from the main checkout, and why
- how long the script took, against the 15-minute limit
- which checks you ran and whether they passed
- any command the checks need that the allowed tools above don't permit, with the
  exact entry to add, such as `Bash(pnpm:*)`

If it still fails after 3 attempts, say what's blocking instead.
