# Guardrails

Checks that a program enforces stop bad pull requests (PRs). AI review helps, but it can't be the last word. Foreman treats the agent as capable but untrusted. Its work should only become a PR after Foreman has checked it with rules that fit the repository's languages. Issue text is input anyone can write, and agents sometimes delete or skip a failing test because that is a smaller edit than fixing the code. Guardrails catch these things mechanically.

Guardrails are being added one step at a time. This page only describes what Foreman does today, and it grows with each step.

## The pipeline at a glance

This is where the guardrails are heading. The first step is built. The others are not yet.

| Step | What it does | State |
| --- | --- | --- |
| Stack detection | Finds which languages a worktree uses, and loads each one's rules. See [Stack rules](#stack-rules). | Built |
| Check commands | Moves a run to **Running checks** when the agent runs a test, lint or type-check command. See [Runs and statuses](runs.md#the-stations). | Built for PHP, TypeScript, Rust and Python |
| Protected files and commands | A hook that blocks edits to protected files, and commands such as `git push` and `gh`. See [Protected files and commands](#protected-files-and-commands). | Built |
| Everything after that | Other hooks, a check Foreman runs itself, risk tiers, review and a CI kit for your repository. | Not built yet |

The agent still opens its own PR for now, as described in [Runs and statuses](runs.md#how-a-run-ends). The protected-commands hook below blocks `git push` and `gh pr create`, so until Foreman pushes and opens PRs itself, a run can't finish that way. Getting a run going is covered in [Getting started](getting-started.md).

## Stack rules

Foreman finds a repository's stacks from marker files anywhere in the worktree. A Tauri app's `src-tauri/Cargo.toml` counts, so it is a Rust project nested in a TypeScript one. Folders such as `vendor`, `node_modules`, `target` and `venv`, and any folder starting with a dot, are skipped.

A repository can have several stacks. Its rules are the union of each stack's rules.

| | PHP | TypeScript / React | Rust | Python |
| --- | --- | --- | --- | --- |
| **Marker file** | `composer.json` | `package.json` | `Cargo.toml` | `pyproject.toml`, `requirements*.txt`, `setup.py` |
| **Protected config** | `phpunit.xml*`, `phpstan*.neon`, `rector.php`, `pint.json` | `tsconfig*.json`, `eslint.config.*`, `.eslintrc*`, `vitest`, `jest` and `playwright` configs, `biome.json`, `.prettierrc*` | `rustfmt.toml`, `clippy.toml`, `deny.toml`, `.cargo/config.toml`, `rust-toolchain*`, `src-tauri/capabilities/**` | `ruff.toml`, `mypy.ini`, `pytest.ini`, `tox.ini`, `setup.cfg`, and `[tool.*]` tables in `pyproject.toml` |
| **Lock files** | `composer.lock` | `package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`, `bun.lock` | `Cargo.lock` | `uv.lock`, `poetry.lock` |
| **Test files** | `tests/**` | `*.test.*`, `*.spec.*`, `__tests__/**`, `e2e/**` | `tests/**`, plus inline `#[cfg(test)]` | `tests/**`, `test_*.py`, `*_test.py`, `conftest.py` |
| **Test markers** | `test(`, `it(`, `function test` | `it(`, `test(` | `#[test]`, `#[tokio::test]` | `def test_` |
| **Assertion markers** | `expect(`, `assert` | `expect(` | `assert!`, `assert_eq!`, `assert_ne!`, `#[should_panic]` | `assert `, `self.assert`, `pytest.raises` |
| **Skip and suppress markers** | `markTestSkipped`, `markTestIncomplete`, `->skip(`, `->todo(`, `@pest-mutate-ignore`, `@phpstan-ignore` | `.skip(`, `.only(`, `xit(`, `xdescribe(`, `.todo(`, `@ts-ignore`, `@ts-expect-error`, `eslint-disable` | `#[ignore]`, `#[allow(clippy::`, `#[allow(dead_code)]` | `pytest.mark.skip`, `skipif`, `xfail`, `unittest.skip`, `pytest.skip(`, `# type: ignore`, `# noqa` |
| **Commit-time formatter** | Pint, or PHP CS Fixer, when the repository requires it | Biome or Prettier, when the repository has it | `rustfmt` | `ruff format` |
| **Default verify commands** | Pint check, PHPStan, then Pest or PHPUnit. A tool runs only if the repository requires it. | The `lint`, `typecheck` and `test` scripts, using the package manager of the lock file. `npx tsc --noEmit` when there is a `tsconfig.json` and no `typecheck` script. | `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` and `cargo test`, in each folder with a `Cargo.toml` | `ruff format --check`, `ruff check`, `mypy` and `pyright` when configured, then `pytest`. With `uv.lock` or `poetry.lock`, each runs through `uv run` or `poetry run`. |
| **High-risk paths** | See the PHP frameworks below | `middleware.ts`, `**/auth/**`, `app/api/**`, `pages/api/**` | `src-tauri/tauri.conf.json`, `src-tauri/capabilities/**`, `**/auth*` | `settings.py`, `**/middleware*.py`, `**/permissions.py`, `**/migrations/**`, `**/auth/**` |
| **Risky code** | See the PHP frameworks below | `dangerouslySetInnerHTML`, `eval(`, `child_process`, `innerHTML` | `unsafe `, `std::process::Command`, `transmute` | `eval(`, `exec(`, `pickle`, `shell=True`, `.raw(`, `.extra(`, `yaml.load(` |

A change to a high-risk path, or new risky code, marks the work as higher risk.

### PHP frameworks

Foreman reads the `require` and `require-dev` lists in `composer.json` to tell two PHP frameworks apart. Only the high-risk paths and risky code differ. Everything else in the PHP column is shared.

| | Laravel (`laravel/framework`) | CodeIgniter 4 (`codeigniter4/framework`) |
| --- | --- | --- |
| **High-risk paths** | `app/Policies/**`, `app/Http/Middleware/**`, `routes/**`, `config/auth.php`, `config/sanctum.php`, `database/migrations/**` | `app/Config/Filters.php`, `Routes.php`, `Security.php`, `Cors.php` and `Encryption.php`, `app/Filters/**`, `app/Database/Migrations/**` |
| **Risky code** | `DB::raw`, `whereRaw` and the other raw query methods, `unguard`, `$request->all()`, `Gate::define` | `->query(` with a variable inside the SQL string, `->protect(false)`, `getPost()` or `getVar()` passed straight to a model, `->setFilter`, and `'except' =>` changes |

When a CodeIgniter 4 repository also requires `codeigniter4/shield`, the high-risk paths add `app/Config/Auth.php`, `AuthGroups.php`, `AuthToken.php` and `AuthJWT.php`. The risky code adds `addPermission`, `addGroup`, `syncGroups` and `loginById`.

### What these rules don't cover

- Every list is a starting point. Foreman holds these rules but does not enforce them yet. Later guardrail steps will use them.
- The skip markers match text. A `@phpstan-ignore` or `@ts-expect-error` with a written reason will be allowed once the check that uses these tables exists.
- A repository with no framework in `composer.json` gets the shared PHP rules and no high-risk paths or risky code.
- Foreman can't see a stack that has no marker file.

## Protected files and commands

Every run starts Claude with its own settings file. The file adds a `PreToolUse` hook, which Claude runs before each `Edit`, `Write`, `MultiEdit`, `NotebookEdit` and `Bash` call. The hook runs one Foreman command, `php artisan foreman:guard`. The command reads the tool call on its input and decides if it may go ahead.

The settings file and its rules file are in `storage/foreman/hooks/`, outside the worktree. So the agent can't edit them.

### What the hook blocks

| Blocked | Details |
| --- | --- |
| Stack config | Each stack's protected config from the [Stack rules](#stack-rules) table, such as `phpunit.xml`, `phpstan.neon` and `tsconfig.json`. |
| Locked paths | The patterns in the repository's **Locked paths** setting. See [Repository settings](repository-settings.md#locked-paths). |
| Repository controls | CI workflows (`.github/workflows/`), `CLAUDE.md`, `AGENTS.md`, `.claude/`, and `.env` files. `.env.example` is allowed, because it holds no secrets and setup scripts copy it. |
| Lock files | Each stack's lock files, unless the issue has the `dependencies` label. |
| Commands | `git push`, any `gh` command, and `--no-verify`. |
| Skip and suppress markers | An edit that adds more of a stack's skip marker than it removes, such as `markTestSkipped`, `.skip(` or `@phpstan-ignore`. |

Each block gives the agent a plain reason, so it can try another way. Foreman also writes a line to the run log, like `Blocked by guardrail: git push is blocked. …`. Blocks show on the run page. See [Troubleshooting](troubleshooting.md#blocked-by-guardrail-in-the-log).

Tools other than the five above, such as `Read`, are never blocked.

### Why exit code 2 matters

Claude Code treats the hook's exit code as the answer. Only exit code 2 blocks the tool call. Exit code 1 counts as a hook error that Claude logs and then ignores, so the call goes ahead. A guard that exits 1 would look like it works and block nothing.

So the command exits 2 for every block. It also exits 2 if it can't read its rules or the tool call, so a broken guard blocks instead of letting everything through. The tests pipe real hook input into the command and check for exit code 2.

### Why existing tests aren't locked outright

The agent has to change tests: it adds tests for new work, and it updates old ones when behavior changes on purpose. Locking every test file would stop that. A broad lock would also push the agent to work around it, or to give up on a good change.

Instead, a separate test-integrity check will fail a run that deletes tests, adds skip markers, or ends with fewer assertions. That check isn't built yet. Until it is, only the skip-marker block above applies. When one test file matters, add it to **Locked paths**.

### What this doesn't catch

- Matching is on text. A command built from variables or run through `sh -c "…"` can get past the `git push` and `gh` rules. The guard works with the allowed-tools list, and doesn't replace it.
- A `gh` or `git push` inside a quoted string can be blocked by mistake, and the agent sees the reason.
- Edits made by a shell command, such as `sed -i` or `echo >`, are not edits to the guard. It only sees `Edit`-style tools. The next guardrail steps check the finished diff.
- Paths are matched as written. A file reached through a symlink to a protected file isn't recognized.
