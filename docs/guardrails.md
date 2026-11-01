# Guardrails

Checks that a program enforces stop bad pull requests (PRs). AI review helps, but it can't be the last word. Foreman treats the agent as capable but untrusted. Its work should only become a PR after Foreman has checked it with rules that fit the repository's languages. Issue text is input anyone can write, and agents sometimes delete or skip a failing test because that is a smaller edit than fixing the code. Guardrails catch these things mechanically.

Guardrails are being added one step at a time. This page only describes what Foreman does today, and it grows with each step.

## The pipeline at a glance

This is where the guardrails are heading. The first steps are built. The others are not yet.

| Step | What it does | State |
| --- | --- | --- |
| Intake gate | Takes only issues that a trusted person marked as ready. See [Intake gate](#intake-gate). | Built |
| Stack detection | Finds which languages a worktree uses, and loads each one's rules. See [Stack rules](#stack-rules). | Built |
| Sanitised issue | Cleans the issue text and hands it to the agent as untrusted data. See [Sanitised issue](#sanitised-issue). | Built |
| Foreman pushes | The agent can't push or use `gh`. It writes a result file, and Foreman acts on GitHub. See [Foreman pushes, not the agent](#foreman-pushes-not-the-agent). | Built |
| Check commands | Moves a run to **Running checks** when the agent runs a test, lint or type-check command. See [Runs and statuses](runs.md#the-stations). | Built for PHP, TypeScript, Rust and Python |
| Protected files and commands | A hook that blocks edits to protected files, and commands such as `git push` and `gh`. See [Protected files and commands](#protected-files-and-commands). | Built |
| Commit-time formatting | A hook that formats the staged files when the agent runs `git commit`. See [Formatting at commit time](#formatting-at-commit-time). | Built |
| Everything after that | Other hooks, a check Foreman runs itself, risk tiers, review and a CI kit for your repository. | Not built yet |

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

Two public incidents show what can go wrong when an AI agent that can run commands reads text that anyone can write.

- **Clinejection.** Cline's issue-triage workflow ran Claude with shell access and let any GitHub user trigger it. Security researcher Adnan Khan showed that an issue title could tell Claude to run code from an attacker's fork. The triage job shared a cache with Cline's release jobs. Poisoning that cache could run code in a release job, where publishing secrets were available. The triage workflow's own `GITHUB_TOKEN` was restricted. Khan says he tested only on his own mirror of the repository, but someone else found his proof of concept and attacked Cline with it. On February 17, 2026, that attacker used an npm token that Cline had failed to revoke to publish `cline@2.3.0`. The only change was a `postinstall` script that installed OpenClaw globally. Cline calls the payload benign. The version was live for about eight hours. Sources: [Adnan Khan's write-up](https://adnanthekhan.com/posts/clinejection/) and [Cline's post-mortem](https://cline.bot/blog/post-mortem-unauthorized-cline-cli-npm).
- **claude-code-action.** RyotaK of GMO Flatt Security reported that `claude-code-action` in `agent` mode treated any actor whose name ended in `[bot]` as trusted. An attacker could use their own GitHub App to open an issue with an injected prompt. The agent could then read `/proc/self/environ`, which held the OIDC credentials. Those could be swapped for a token that could push to the repository. RyotaK says he tested this only on his own repositories and did not compromise any of Anthropic's. It was fixed in v1.0.94. Source: [RyotaK's write-up](https://flatt.tech/research/posts/poisoning-claude-code-one-github-issue-to-break-the-supply-chain/).

Both cases started the same way: untrusted text reached an agent that could run commands. The sources fit our point in part. In `claude-code-action`, the agent's environment held credentials it didn't need. In Clinejection, the triage agent never held the publishing secrets. Its problem was broad shell access for anyone who could open an issue, plus a cache shared with the release jobs. So the lesson is to give the agent as little as possible. An agent with no push rights and no token can be tricked into writing a bad result file, but it can't push to a branch or publish a package. A person still reviews the PR. This does not cover shared caches or other ways code can reach a release process.

### What it doesn't catch

- The agent still writes the PR's title and body, and the comment on the issue. Foreman posts them as written.
- Code the agent commits is still pushed. A bad change can reach a PR, which is why the other guardrails and your review still matter.
- Setup scripts run before the agent starts, as you, with your credentials. See [Repository settings](repository-settings.md#setup-script).
- The push block is a guardrail, not a sandbox. Allowed tools such as `npm`, `composer`, `cargo` and `python` run any code, and that code could set its own git configuration. The push URL and the missing tokens stop the agent's normal commands.
- The agent can still reach the network through allowed tools such as `npm` and `composer`.
- During a fix-up round the allowed tools still include `gh pr checks` and `gh run view`, but the [protected-commands hook](#protected-files-and-commands) blocks every `gh` command. So the agent can't read CI results itself yet.

## Intake gate

Issue text is the agent's instructions, and anyone who can edit an issue can change it. So Foreman only takes an issue when a trusted person marked it as ready. On every check it skips an issue when:

- the ready label was added by a bot, or
- the ready label was added by someone without write access to the repository, or
- the issue was edited after the label was added.

To let bots mark issues as ready, turn on **Trust ready labels added by bots** on the Settings page. It's off by default. A trusted bot's label skips the write-access check, but an edit after the label still skips the issue. See [Global settings](global-settings.md#intake-gate).

Admin, maintain and write access all count as write access. Foreman asks GitHub once per check for each person who added a label, however many issues they labelled.

A skipped issue stays open and keeps its label. The repository's row on the dashboard shows it under **Skipped by the intake gate**, with the reason. To approve it, read the issue, then remove the label and add it again. A person with write access has to do that. If GitHub shows no record of who added the label, Foreman skips the issue too.

Issues that pass are picked up as before.

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

## Formatting at commit time

When the agent runs `git commit`, Foreman first formats the staged files and stages them again. The commit then holds formatted code. This is a second `PreToolUse` hook on `Bash` calls. It runs `php artisan foreman:format-staged`, next to the guard above.

For each stack, Foreman picks the formatter from the [Stack rules](#stack-rules) table. It passes the staged files that the formatter handles, such as `.php` files for Pint or `.rs` files for `rustfmt`. Then it runs `git add` on those files.

| Situation | What happens |
| --- | --- |
| A staged file is not formatted | Foreman formats it, stages it again, and the commit goes ahead. |
| The formatter fails, for example on a syntax error | The hook exits with code 2. The commit is blocked, and the agent sees the formatter's output. |
| The stack's formatter is not installed | That stack is skipped. This is not a failure. |
| The command is not a `git commit` | The hook does nothing. |

### Why it runs at commit time

The timing is on purpose. A formatter that ran after every edit would change files while the agent is still working on them. It would also add noise to each step. Running once at commit time lets the agent finish a change first.

### Why it is not a git hook

A git pre-commit hook lives in the `.git` folder. All worktrees of a repository share that folder, so the hook would also run on your own commits in the main checkout. A Claude hook is part of one run's settings file. It only runs for the agent, and it goes away with the run.

### What this doesn't cover

- The formatter runs from the worktree root. For a stack in a sub-folder, a formatter in that folder's own `vendor` or `node_modules` is not found, so it is skipped.
- A file that is only partly staged gets staged in full.
- The hook matches `git commit` as text. A commit made through `sh -c "…"` is not formatted.
