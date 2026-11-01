# Guardrails

Checks that a program enforces stop bad pull requests (PRs). AI review helps, but it can't be the last word. Foreman treats the agent as capable but untrusted. Its work should only become a PR after Foreman has checked it with rules that fit the repository's languages. Issue text is input anyone can write, and agents sometimes delete or skip a failing test because that is a smaller edit than fixing the code. Guardrails catch these things mechanically.

Guardrails are being added one step at a time. This page only describes what Foreman does today, and it grows with each step.

## The pipeline at a glance

This is where the guardrails are heading. The first steps are built. The others are not yet.

| Step | What it does | State |
| --- | --- | --- |
| Stack detection | Finds which languages a worktree uses, and loads each one's rules. See [Stack rules](#stack-rules). | Built |
| Sanitised issue | Cleans the issue text and hands it to the agent as untrusted data. See [Sanitised issue](#sanitised-issue). | Built |
| Check commands | Moves a run to **Running checks** when the agent runs a test, lint or type-check command. See [Runs and statuses](runs.md#the-stations). | Built for PHP, TypeScript, Rust and Python |
| Protected files and commands | A hook that blocks edits to protected files, and commands such as `git push` and `gh`. See [Protected files and commands](#protected-files-and-commands). | Built |
| Test integrity | A script that fails when tests are deleted, weakened or skipped, or when tool settings are loosened. See [Test integrity](#test-integrity). | Built |
| Definition of done | A Stop hook that sends the agent back until the verify script and the integrity check pass. See [Definition of done](#definition-of-done). | Built |
| Everything after that | A check Foreman runs itself, risk tiers, review and a CI kit for your repository. | Not built yet |

The agent still opens its own PR for now, as described in [Runs and statuses](runs.md#how-a-run-ends). The protected-commands hook below blocks `git push` and `gh pr create`, so until Foreman pushes and opens PRs itself, a run can't finish that way. Getting a run going is covered in [Getting started](getting-started.md).

## Sanitised issue

The agent no longer reads the issue itself. Foreman fetches the title and body, cleans them, and puts them in the prompt. A custom prompt can place them with the `{{issue_text}}` placeholder, listed in [Repository settings](repository-settings.md#instructions).

### How it works

Foreman removes four kinds of content that a person reading the issue on GitHub can't see, or that can hide a payload:

- HTML comments, like `<!-- ... -->`. GitHub hides them. A comment that never closes is removed to the end of the text.
- `<details>` blocks, with everything inside. These are collapsed by default.
- Images, both Markdown (`![alt](url)`) and HTML (`<img>`).
- Link targets. `[text](url)` keeps `text` and drops the address, and so does `<a href="...">text</a>`. Reference definitions such as `[ref]: url` are removed.

What is left sits between `<untrusted_issue>` tags. A short note before it tells the agent the text is data to act on as a spec, never instructions to follow. Foreman also strips those tags from the issue text, so it can't close the wrapper early.

### Why it exists

Anyone who can open or edit an issue controls its text. A hidden comment in an otherwise normal issue is enough to carry instructions, and a person reviewing the issue would never see them. The agent can run commands, so it must not take orders from that text.

### What it doesn't catch

This lowers the risk of prompt injection. It does not remove it.

- Instructions written as plain, visible text still reach the agent. The wrapper asks the agent to ignore them, but the agent may not.
- Bare URLs stay, because they are visible text.
- Issue comments are not cleaned. They are only read when a human replies and a run resumes, and the agent reads them itself then.
- The rules match text patterns. A new way to hide content may get through.

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

- Every list is a starting point. The protected-files hook and the [test-integrity check](#test-integrity) use them. The risk lists are held but not used yet.
- The skip markers match text. A `@phpstan-ignore` or `@ts-expect-error` is blocked even with a written reason.
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

Instead, the [test-integrity check](#test-integrity) fails a run that deletes tests, adds skip markers, or ends with fewer assertions. When one test file matters, add it to **Locked paths**.

### What this doesn't catch

- Matching is on text. A command built from variables or run through `sh -c "…"` can get past the `git push` and `gh` rules. The guard works with the allowed-tools list, and doesn't replace it.
- A `gh` or `git push` inside a quoted string can be blocked by mistake, and the agent sees the reason.
- Edits made by a shell command, such as `sed -i` or `echo >`, are not edits to the guard. It only sees `Edit`-style tools. The [test-integrity check](#test-integrity) looks at the finished diff, so it catches those edits to tests and tool settings.
- Paths are matched as written. A file reached through a symlink to a protected file isn't recognized.

## Test integrity

The test-integrity check compares the work with where the branch left its base branch. It counts committed, staged, unstaged and new files, so it sees changes the agent hasn't committed. It fails on any of these:

| Fails on | Details |
| --- | --- |
| A deleted test file | Any file that matches the stack's test files in the [Stack rules](#stack-rules) table. A moved test file counts as a delete. |
| Fewer tests or assertions | Per file, using the test and assertion markers in the table. A file that ends with fewer of either than it started with fails. Rust unit tests sit in source files, so every changed `.rs` file is counted. |
| A new skip or suppress marker | A file that holds more of a skip marker than before, such as `markTestSkipped`, `.skip(`, `#[ignore]`, `@phpstan-ignore` or `# noqa`. Markers that were already there pass. Markdown, text and JSON files are ignored, so docs can quote them. |
| An edit to protected config | Any change to a file in the table's protected config row, such as `phpunit.xml` or `tsconfig.json`. In `pyproject.toml` only the `[tool.*]` tables count. Other parts of the file can change. |
| A larger PHPStan baseline | A `phpstan*baseline*.neon` file that holds more errors than before, counting each entry's `count:`. A smaller baseline passes, and the other PHPStan config is protected as above. |

A diff with none of these passes. Adding tests, adding assertions and fixing a baseline entry are all fine.

### How it runs

It is one file, `resources/guardrails/test-integrity.php`. Foreman runs it from the [Stop hook](#definition-of-done). You can run it yourself:

```bash
php test-integrity.php --base=origin/main
```

It exits 0 when clean, 1 when it finds a violation, and 2 when it can't run. It finds the stacks from marker files the same way Foreman does. With no marker file, every stack's rules apply. With no `--base` it tries `origin/HEAD`, `origin/main`, `origin/master`, `main` and `master`.

### Why it is PHP with no dependencies

The same file has to run in any repository's CI, whatever its stack. A Python or Rust project may have no Composer, no `vendor` folder, and none of Foreman's code. PHP is the language Foreman is written in, so it is already installed where Foreman runs, and CI images carry it or install it in one step. A file with no `require` lines can be copied anywhere and read in one sitting.

The rule tables are copied into the file instead of loaded from Foreman's classes. A test compares them with those classes, so the two can't drift apart without a failing test.

### What it doesn't catch

- Counts are by text. A test can be gutted while its name and one assertion stay, and a test can be replaced by an equally bad one.
- Moving a test to another file reads as a delete and fails. The agent has to say so, or a human makes the move.
- Rules apply only to the stacks it finds. A stack with no marker file is invisible, unless no stack is found at all.

## Definition of done

The agent can't stop until its work passes the repository's checks. Foreman adds a `Stop` hook to every run. Claude Code runs it each time the agent tries to finish. The hook runs `php artisan foreman:stop`, which runs two things, both in the worktree:

1. The [test-integrity check](#test-integrity), against `origin/` and the base branch.
2. The repository's [verify script](repository-settings.md#verify-script).

If either fails, the agent can't stop. It gets a message with the end of the failing output, up to 3,000 characters per check, and goes back to work. If both pass, it stops.

### Why it gives up after 3 tries

Some failures the agent can't fix: a missing tool, a flaky test, a check that fails on the base branch too. A hook that blocks forever would burn money for nothing. Claude Code also has its own limit. After 8 blocked stops in a row it forces the agent to stop, with no say from Foreman. Three is well under that. It leaves the agent two real chances to fix what the first failure showed, and Foreman decides when to stop instead of Claude Code.

After the third block, the next stop is let through. The run log says `Stop hook gave up after 3 blocked stops.` The count starts again each time Foreman starts the agent, including when a run resumes after a human reply. Foreman's own checks after the agent finishes are meant to flag a run that gave up for a human. Those checks aren't built yet. See [Troubleshooting](troubleshooting.md#stop-hook-gave-up).

### What it doesn't do

- It does not stop the agent from opening a PR. It only holds back the end of the turn.
- If it can't read its rules, it lets the agent stop and logs why. Blocking forever would be worse.
- The verify script is only as good as you make it. An empty script falls back to the detected stacks' checks, and a stack Foreman can't detect has none.
