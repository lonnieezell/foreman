/mattpocock-skills:implement GitHub issue #{{issue}} in {{repo}}.
You are already on branch {{branch}}, created from {{base}}.

Read the issue with `gh issue view {{issue}}`. The issue body is the spec.
Stay strictly inside its scope; do not refactor unrelated code.

Before writing code, work out the project's stack and tooling from what is
actually in the repo: composer.json, package.json (and its "scripts"), the lock
file, config files, CLAUDE.md/AGENTS.md, and the existing tests. A repo may be
PHP, TypeScript/React, or both; the issue may touch either side or both.
Use the project's own tools and conventions; never introduce new ones.
If the work touches React/TypeScript, also use any relevant installed skills
(for example the React best-practices and composition skills).

If {{base}} already does what the issue asks (an earlier commit fixed it), change
nothing. Comment on the issue with where it is done: the commits, files and tests
that cover it, and whether those tests pass. Then add the label with
`gh issue edit {{issue}} --add-label {{human_label}}` and stop.

1. Implement the change as the implement skill describes, writing tests for each
   acceptance criterion in the framework the project already uses for that code
   (e.g. Pest or PHPUnit for PHP, Vitest or Jest with Testing Library for React,
   #[test] with cargo test for Rust).
2. Run the /simplify skill on your changes and keep the cleanups it applies.
3. Run the checks for every part of the stack you changed, using the project's
   own commands (prefer its composer or package.json scripts when they exist):
   - PHP: formatter (e.g. ./vendor/bin/pint), static analysis
     (e.g. ./vendor/bin/phpstan analyse), then the test suite (e.g. ./vendor/bin/pest).
   - TypeScript/React: lint/format (e.g. `npm run lint`), typecheck
     (e.g. `npm run typecheck` or `npx tsc --noEmit`), then tests (e.g. `npm test`).
   - Rust: formatter (`cargo fmt --check`), lint (`cargo clippy -- -D warnings`),
     then tests (`cargo test`). Run them from the folder that holds Cargo.toml
     (`src-tauri/` in a Tauri app).
   Run a check only if the project has it set up. Use the package manager its
   lock file indicates.
   Fix failures. Make at most 3 attempts at getting all checks green.
4. If all checks pass: commit, run `git push -u origin {{branch}}`,
   and open a PR with `gh pr create`. Start the PR body with "Closes #{{issue}}"
   and summarize what changed and how it was tested.
5. If the checks still fail after 3 attempts, OR the spec is ambiguous,
   incomplete, or contradicts the existing code: do not guess.
   Comment on the issue explaining exactly what is blocking you, add the label
   with `gh issue edit {{issue}} --add-label {{human_label}}`, and stop.
