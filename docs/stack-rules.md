# Stack rules

The rules Foreman loads for each language, or *stack*. [Guardrails](guardrails.md) explains how each rule is used.

Foreman finds a repository's stacks from marker files anywhere in the worktree. A Tauri app's `src-tauri/Cargo.toml` counts, so it is a Rust project nested in a TypeScript one. The folders `vendor`, `node_modules`, `target`, `venv` and `__pycache__`, and any folder starting with a dot, are skipped.

A repository can have several stacks. Its rules are the union of each stack's rules.

| | PHP | TypeScript / React | Rust | Python |
| --- | --- | --- | --- | --- |
| **Marker file** | `composer.json` | `package.json` | `Cargo.toml` | `pyproject.toml`, `requirements*.txt`, `setup.py` |
| **Protected config** | `phpunit.xml*`, `phpstan*.neon`, `rector.php`, `pint.json` | `tsconfig*.json`, `eslint.config.*`, `.eslintrc*`, `vitest`, `jest` and `playwright` configs, `biome.json`, `biome.jsonc`, `.prettierrc*` | `rustfmt.toml`, `.rustfmt.toml`, `clippy.toml`, `.clippy.toml`, `deny.toml`, `.cargo/config.toml`, `rust-toolchain*`, `src-tauri/capabilities/**` | `ruff.toml`, `.ruff.toml`, `mypy.ini`, `pytest.ini`, `tox.ini`, `setup.cfg`, and `[tool.*]` tables in `pyproject.toml` (test integrity only) |
| **Lock files** | `composer.lock` | `package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`, `bun.lock`, `bun.lockb` | `Cargo.lock` | `uv.lock`, `poetry.lock` |
| **[Sandbox](repository-settings.md#sandbox) hosts** | `packagist.org`, `repo.packagist.org` | `registry.npmjs.org`, `registry.yarnpkg.com` | `crates.io`, `index.crates.io`, `static.crates.io` | `pypi.org`, `files.pythonhosted.org` |
| **Test files** | `tests/**` | `*.test.*`, `*.spec.*`, `__tests__/**`, `e2e/**` | `tests/**`, plus inline `#[cfg(test)]` | `tests/**`, `test_*.py`, `*_test.py`, `conftest.py` |
| **Test markers** | `test(`, `it(`, `function test` | `it(`, `test(` | `#[test]`, `#[tokio::test]` | `def test_` |
| **Assertion markers** | `expect(`, `assert` | `expect(` | `assert!`, `assert_eq!`, `assert_ne!`, `#[should_panic]` | `assert `, `self.assert`, `pytest.raises` |
| **Skip and suppress markers** | `markTestSkipped`, `markTestIncomplete`, `->skip(`, `->todo(`, `@pest-mutate-ignore`, `@phpstan-ignore` | `.skip(`, `.only(`, `xit(`, `xdescribe(`, `.todo(`, `@ts-ignore`, `@ts-expect-error`, `eslint-disable` | `#[ignore]`, `#[allow(clippy::`, `#[allow(dead_code)]` | `pytest.mark.skip`, `skipif`, `xfail`, `unittest.skip`, `pytest.skip(`, `# type: ignore`, `# noqa` |
| **Commit-time formatter** | Pint, or PHP CS Fixer, when the repository requires it | Biome or Prettier, when the repository has it | `rustfmt` | `ruff format` |
| **Default verify commands** | Pint check (or PHP CS Fixer) and PHPStan, each only if the repository requires it. Then Pest if required, or PHPUnit otherwise. The format check covers only the changed files. | The `lint`, `typecheck` and `test` scripts, using the package manager of the lock file. `npx tsc --noEmit` when there is a `tsconfig.json` and no `typecheck` script. | `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` and `cargo test`, in each folder with a `Cargo.toml` | `ruff format --check`, `ruff check`, `mypy` and `pyright` when configured, then `pytest`. With `uv.lock` or `poetry.lock`, each runs through `uv run` or `poetry run`. Both `ruff` checks cover only the changed files. |
| **High-risk paths** | See the PHP frameworks below | `middleware.ts`, `**/auth/**`, `app/api/**`, `pages/api/**` | `src-tauri/tauri.conf.json`, `src-tauri/capabilities/**`, `**/auth*` | `settings.py`, `**/middleware*.py`, `**/permissions.py`, `**/migrations/**`, `**/auth/**` |
| **Risky code** | See the PHP frameworks below | `dangerouslySetInnerHTML`, `eval(`, `child_process`, `innerHTML` | `unsafe `, `std::process::Command`, `transmute` | `eval(`, `exec(`, `pickle`, `shell=True`, `.raw(`, `.extra(`, `yaml.load(` |

A change to a high-risk path, or new risky code, makes the change [risk tier 3](guardrails.md#risk-tiers).

## What the default checks cover

A problem that is already on the base branch is not the agent's to fix. Fixing it would break the agent's scope, and leaving it would fail every run. So the default format and lint checks look only at the files the change touches. Tests and static analysis still cover the whole repository, because a broken test or type error on the base branch is worth a human's attention.

| Check | Covers |
| --- | --- |
| Pint, PHP CS Fixer | Changed files only |
| `ruff format --check`, `ruff check` | Changed files only |
| PHPStan, `mypy`, `pyright`, `tsc` | The whole project |
| Pest, PHPUnit, `pytest`, `cargo test`, the `test` script | The whole project |
| `cargo fmt --check` | The whole crate. `rustfmt` follows each `mod` line into other files, so a changed `lib.rs` would still check files the agent never touched. |
| `cargo clippy` | The whole crate. Clippy can't be limited to some files. |
| The TypeScript `lint` script | The whole project. The script is the repository's own, so Foreman can't tell which files it checks. |

"Changed files" means the files with the tool's extension that differ from where the branch left `origin/<base>`. That counts committed, staged, unstaged and new files, the same as the [test-integrity check](guardrails.md#test-integrity). Deleted files are skipped. When nothing changed, the check passes without running the tool. When the base ref is missing, the check fails instead of passing.

Foreman passes the file list itself instead of using Pint's `--diff` option. With `--diff`, Pint checks nothing at all for a project in a sub-folder.

A [verify script](repository-settings.md#verify-script) you write yourself runs as written. These rules only shape the default.

## PHP frameworks

Foreman reads the `require` and `require-dev` lists in `composer.json` to tell two PHP frameworks apart. Only the high-risk paths and risky code differ. Everything else in the PHP column is shared.

| | Laravel (`laravel/framework`) | CodeIgniter 4 (`codeigniter4/framework`) |
| --- | --- | --- |
| **High-risk paths** | `app/Policies/**`, `app/Http/Middleware/**`, `routes/**`, `config/auth.php`, `config/sanctum.php`, `database/migrations/**` | `app/Config/Filters.php`, `Routes.php`, `Security.php`, `Cors.php` and `Encryption.php`, `app/Filters/**`, `app/Database/Migrations/**` |
| **Risky code** | `DB::raw`, `whereRaw` and the other raw query methods, `unguard`, `$request->all()`, `Gate::define` | `->query(` with a variable inside the SQL string, `->protect(false)`, `getPost()` or `getVar()` passed straight to a model, `->setFilter`, and `'except' =>` changes |

When a CodeIgniter 4 repository also requires `codeigniter4/shield`, the high-risk paths add `app/Config/Auth.php`, `AuthGroups.php`, `AuthToken.php` and `AuthJWT.php`. The risky code adds `addPermission`, `addGroup`, `syncGroups` and `loginById`.

## What these rules don't cover

- Every list is a starting point. The protected-files hook and the [test-integrity check](guardrails.md#test-integrity) use them. The risk lists set the [risk tier](guardrails.md#risk-tiers).
- The skip markers match text. A `@phpstan-ignore` or `@ts-expect-error` is blocked even with a written reason.
- A repository with no framework in `composer.json` gets the shared PHP rules and no high-risk paths or risky code.
- Foreman can't see a stack that has no marker file.
