# Guardrails

Checks that a program enforces stop bad pull requests (PRs). AI review helps, but it can't be the last word. Foreman treats the agent as capable but untrusted. Its work should only become a PR after Foreman has checked it with rules that fit the repository's languages. Issue text is input anyone can write, and agents sometimes delete or skip a failing test because that is a smaller edit than fixing the code. Guardrails catch these things mechanically.

Guardrails are being added one step at a time. This page only describes what Foreman does today, and it grows with each step.

## The pipeline at a glance

This is where the guardrails are heading. The first steps are built. The others are not yet.

| Step | What it does | State |
| --- | --- | --- |
| Stack detection | Finds which languages a worktree uses, and loads each one's rules. See [Stack rules](#stack-rules). | Built |
| Sanitised issue | Cleans the issue text and hands it to the agent as untrusted data. See [Sanitised issue](#sanitised-issue). | Built |
| Foreman pushes | The agent can't push or use `gh`. It writes a result file, and Foreman acts on GitHub. See [Foreman pushes, not the agent](#foreman-pushes-not-the-agent). | Built |
| Check commands | Moves a run to **Running checks** when the agent runs a test, lint or type-check command. See [Runs and statuses](runs.md#the-stations). | Built for PHP, TypeScript, Rust and Python |
| Everything after that | Hooks inside the agent run, a check Foreman runs itself, risk tiers, review and a CI kit for your repository. | Not built yet |

Foreman pushes and opens the PR, not the agent. See [Foreman pushes, not the agent](#foreman-pushes-not-the-agent). Getting a run going is covered in [Getting started](getting-started.md).

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

## Foreman pushes, not the agent

The agent never acts on GitHub. It commits in its worktree and writes `.foreman/result.json`. Foreman reads the file and then comments, labels, pushes and opens the PR. The three outcomes are listed in [Runs and statuses](runs.md#how-a-run-ends).

### How it works

- **The environment.** The agent's process gets a push URL for `origin` that points nowhere, an empty git credential helper, and no `GH_TOKEN`, `GITHUB_TOKEN`, `GH_ENTERPRISE_TOKEN` or `GITHUB_ENTERPRISE_TOKEN`. It also has no git or SSH password prompt and no SSH agent. A `git push origin` fails. Foreman's own push runs in a separate process without these changes.
- **The allowed tools.** The [default list](repository-settings.md#allowed-tools) has no `gh` and no `git push`. Only the git commands that inspect and commit are allowed.
- **The permission mode.** Runs use Claude's `dontAsk` mode. A tool that isn't on the list is refused, not asked about.
- **A missing or unreadable result file fails the run.** Foreman never guesses what the agent meant.

### Why it exists

Two public incidents show what happens when an AI agent holds credentials that can change a repository and reads text that anyone can write.

- **Clinejection.** Researchers showed that an issue title could carry instructions to an AI triage workflow on the Cline repository. The agent ran with enough access that the instructions led to stolen publishing credentials.
- **claude-code-action.** Researchers reported that prompt injection through content such as issue and PR text could make the agent use the GitHub token it was given.

In both, the weak point was not the model's judgement. It was that the agent held rights it didn't need. An agent with no push rights and no token can be tricked into writing a bad result file, but it can't push to a branch or publish a package. A person still reviews the PR.

### What it doesn't catch

- The agent still writes the PR's title and body, and the comment on the issue. Foreman posts them as written.
- Code the agent commits is still pushed. A bad change can reach a PR, which is why the other guardrails and your review still matter.
- Setup scripts run before the agent starts, as you, with your credentials. See [Repository settings](repository-settings.md#setup-script).
- The push block is a guardrail, not a sandbox. Allowed tools such as `npm`, `composer`, `cargo` and `python` run any code, and that code could set its own git configuration. The push URL and the missing tokens stop the agent's normal commands.
- The agent can still reach the network through allowed tools such as `npm` and `composer`.
- During a fix-up round the agent may run `gh pr checks` and `gh run view`. With no token in its environment, those only work if `gh` keeps its login in its own config.

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
