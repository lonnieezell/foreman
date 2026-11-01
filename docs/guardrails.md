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
| Test integrity | A script that fails when tests are deleted, weakened or skipped, or when tool settings are loosened. See [Test integrity](#test-integrity). | Built |
| Definition of done | A Stop hook that sends the agent back until the verify script and the integrity check pass. See [Definition of done](#definition-of-done). | Built |
| Commit-time formatting | A hook that formats the staged files when the agent runs `git commit`. See [Formatting at commit time](#formatting-at-commit-time). | Built |
| Foreman's gate | Foreman runs the verify script and the integrity check again, outside any hook, and checks the size of the change before it pushes. See [Foreman's gate](#foremans-gate). | Built |
| Risk tiers and review | Rates the change from its diff, then has the agent review it as deeply as the tier calls for. The gate runs again afterwards. See [Risk tiers](#risk-tiers). | Built |
| Everything after that | Other hooks and a CI kit for your repository. | Not built yet |

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
- Issue comments, bot threads and CI logs that reach the agent in a round or a resume are wrapped as untrusted, but the sanitiser's cleanup is not applied to logs. See [Rounds and resumes follow the same rules](#rounds-and-resumes-follow-the-same-rules).
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

### Rounds and resumes follow the same rules

[Fix-up rounds](pr-watching.md#fix-up-rounds) and **Continue after my reply** resume the agent's session, and they keep every rule above.

- **The agent still has no `gh` and no push.** It gets the same allowed tools as a first run. Foreman fetches the failed CI logs and the new issue comments, puts them in the prompt, and pushes the round's commits itself.
- **Text from other people is untrusted.** Bot thread bodies, issue comments and CI logs sit between `<untrusted_text>` tags, with a note that they are data and not instructions. Thread bodies and comments get the same [cleanup as the issue](#sanitised-issue). Logs are only trimmed, so their text stays as it is.
- **Only people with write access count.** On a resume, Foreman passes only the comments written since the run stopped, and only from users with write access to the repository. This is the same test as the [intake gate](#intake-gate). A comment from anyone else is left out. A bot's comment is left out too.
- **Wrapping lowers the risk, it doesn't remove it.** Plain, visible text can still carry instructions that the agent may follow.

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
| **Default verify commands** | Pint check (or PHP CS Fixer), PHPStan, then Pest or PHPUnit. A tool runs only if the repository requires it. The format check covers only the changed files. | The `lint`, `typecheck` and `test` scripts, using the package manager of the lock file. `npx tsc --noEmit` when there is a `tsconfig.json` and no `typecheck` script. | `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` and `cargo test`, in each folder with a `Cargo.toml` | `ruff format --check`, `ruff check`, `mypy` and `pyright` when configured, then `pytest`. With `uv.lock` or `poetry.lock`, each runs through `uv run` or `poetry run`. Both `ruff` checks cover only the changed files. |
| **High-risk paths** | See the PHP frameworks below | `middleware.ts`, `**/auth/**`, `app/api/**`, `pages/api/**` | `src-tauri/tauri.conf.json`, `src-tauri/capabilities/**`, `**/auth*` | `settings.py`, `**/middleware*.py`, `**/permissions.py`, `**/migrations/**`, `**/auth/**` |
| **Risky code** | See the PHP frameworks below | `dangerouslySetInnerHTML`, `eval(`, `child_process`, `innerHTML` | `unsafe `, `std::process::Command`, `transmute` | `eval(`, `exec(`, `pickle`, `shell=True`, `.raw(`, `.extra(`, `yaml.load(` |

A change to a high-risk path, or new risky code, makes the change [risk tier 3](#risk-tiers).

### What the default checks cover

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

"Changed files" means the files with the tool's extension that differ from where the branch left `origin/<base>`. That counts committed, staged, unstaged and new files, the same as the [test-integrity check](#test-integrity). Deleted files are skipped. When nothing changed, the check passes without running the tool. When the base ref is missing, the check fails instead of passing.

Foreman passes the file list itself instead of using Pint's `--diff` option. With `--diff`, Pint checks nothing at all for a project in a sub-folder.

A [verify script](repository-settings.md#verify-script) you write yourself runs as written. These rules only shape the default.

### Continuing runs catch up with the base branch

A [fix-up round](pr-watching.md#fix-up-rounds) or a **Continue after my reply** run reuses the branch it left behind. Before the agent starts, Foreman fetches `origin` and merges `origin/<base>` into that branch. A fix that landed on the base branch in the meantime then reaches the run.

When the worktree is gone and Foreman rebuilds it from the pushed branch, the merge runs before the setup script, so dependencies the base branch added get installed. A worktree that is still there is reused as it is, and the setup script doesn't run again.

| Situation | What happens |
| --- | --- |
| The merge goes through | The run goes on from the merged branch. The run log shows `Merging origin/<base> into <branch>`. |
| The merge conflicts, fails or takes over 2 minutes | Foreman aborts the merge and the run goes on from the branch as it was. The log line starts with `Couldn't merge origin/<base>` and shows git's output. |
| Fetching `origin` fails | Foreman doesn't merge. The run goes on from the branch as it was, and the log line starts with `Couldn't fetch origin`. |
| The worktree has uncommitted changes | Foreman doesn't merge, because `git merge --abort` can't always put those changes back. The run goes on from the branch as it was. |

A conflict doesn't stop the run or flag a human. Most fixes on the base branch aren't needed by the run, and the checks above already ignore problems the agent didn't touch. If the branch still needs the fix, [Foreman's gate](#foremans-gate) fails and a human sees why. The branch's commits are never lost: an aborted merge leaves the branch where it was.

### PHP frameworks

Foreman reads the `require` and `require-dev` lists in `composer.json` to tell two PHP frameworks apart. Only the high-risk paths and risky code differ. Everything else in the PHP column is shared.

| | Laravel (`laravel/framework`) | CodeIgniter 4 (`codeigniter4/framework`) |
| --- | --- | --- |
| **High-risk paths** | `app/Policies/**`, `app/Http/Middleware/**`, `routes/**`, `config/auth.php`, `config/sanctum.php`, `database/migrations/**` | `app/Config/Filters.php`, `Routes.php`, `Security.php`, `Cors.php` and `Encryption.php`, `app/Filters/**`, `app/Database/Migrations/**` |
| **Risky code** | `DB::raw`, `whereRaw` and the other raw query methods, `unguard`, `$request->all()`, `Gate::define` | `->query(` with a variable inside the SQL string, `->protect(false)`, `getPost()` or `getVar()` passed straight to a model, `->setFilter`, and `'except' =>` changes |

When a CodeIgniter 4 repository also requires `codeigniter4/shield`, the high-risk paths add `app/Config/Auth.php`, `AuthGroups.php`, `AuthToken.php` and `AuthJWT.php`. The risky code adds `addPermission`, `addGroup`, `syncGroups` and `loginById`.

### What these rules don't cover

- Every list is a starting point. The protected-files hook and the [test-integrity check](#test-integrity) use them. The risk lists set the [risk tier](#risk-tiers).
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

After the third block, the next stop is let through. The run log says `Stop hook gave up after 3 blocked stops.` The count starts again each time Foreman starts the agent, including when a run resumes after a human reply. Foreman's own checks after the agent finishes catch a run that gave up. See [Foreman's gate](#foremans-gate) and [Troubleshooting](troubleshooting.md#stop-hook-gave-up).

### What it doesn't do

- It does not stop the agent from opening a PR. It only holds back the end of the turn.
- If it can't read its rules, it lets the agent stop and logs why. Blocking forever would be worse.
- The verify script is only as good as you make it. An empty script falls back to the detected stacks' checks, and a stack Foreman can't detect has none.

## Foreman's gate

When the agent says it is done with a `pr` result, Foreman doesn't push yet. The run moves to the **Reviewing** station. There Foreman runs these in the worktree:

1. The [test-integrity check](#test-integrity), against `origin/` and the base branch.
2. The repository's [verify script](repository-settings.md#verify-script). An empty script falls back to the detected stacks' checks, as in [Definition of done](#definition-of-done).
3. A size check. It adds up the lines added and deleted against the base branch, and compares the total with the size limit.

If all three pass, Foreman works out the [risk tier](#risk-tiers) and sends the agent through a review pass. Then it runs all three checks again. Only if they pass a second time does Foreman push the branch and open the PR. It adds a footer to the PR body that lists the checks it ran, the integrity result and the size. If any one fails, nothing is pushed. The run ends as **Needs you**. Foreman comments on the issue with the output, up to 3,000 characters per check, and adds the human label. When you reply, **Continue after my reply** sends the agent back into the same worktree to fix it.

### The size limit

The limit is 1,000 changed lines by default. Set `FOREMAN_MAX_PR_LINES` in `.env` to change it, then restart Foreman. See [Global settings](global-settings.md#environment-variables). A change over the limit goes to **Needs you** instead of opening a PR, so a person decides whether to split it up. A change exactly at the limit still opens.

### Why checks run again outside the agent

The [Definition of done](#definition-of-done) already runs the same checks in a hook. That hook is not enough on its own:

- It gives up after 3 blocked stops, and then lets the agent stop with failing checks.
- It runs inside the agent's session. A hook can fail to load, or fail to read its rules, and then it lets the agent stop too.
- The agent can change files after the last time the hook ran, or tell Foreman the work is done without the hook ever running.

Foreman's gate runs in Foreman's own process, after the agent has finished. The agent can't skip it, shorten it or talk its way past it. Foreman only trusts a check that it ran itself.

### What it doesn't do

- It only gates the first PR of a run. Fix-up rounds on a PR that is already open still push without it.
- It is only as good as the verify script. A repository with no script and no detected stack has nothing to run except the integrity check and the size check.
- Counting lines is a blunt measure. A large generated file counts the same as hand-written code. Binary files count as zero.

## Risk tiers

After [Foreman's gate](#foremans-gate) passes the first time, Foreman rates the change. It reads the branch's diff against the base branch and gives it a tier from 1 to 3. The tier decides how deep the AI review goes.

### How the tier is worked out

Foreman checks the rules in this order and stops at the first match:

1. **Tier 3:** a changed file matches a high-risk path of a detected stack. Or an added line matches one of its risky-code patterns. The lists are in [Stack rules](#stack-rules) and [PHP frameworks](#php-frameworks). A renamed file counts under its old path too, so moving a file out of `routes/` is still tier 3.
2. **Tier 1:** every changed file is docs or tests, and the change is under 200 changed lines (added plus deleted). Docs are `*.md`, `*.mdx`, `*.rst` and anything under `docs/`. Tests are the stacks' test files.
3. **Tier 2:** everything else.

The run log names what set the tier, such as `Risk tier 3: touches routes/web.php.`

### What changes at each tier

Foreman resumes the agent's own session for the review pass. The agent keeps everything it learned while writing the change. The run stays on the **Reviewing** station.

| | Tier 1 | Tier 2 | Tier 3 |
| --- | --- | --- | --- |
| `/code-review`, with its fixes applied | Low effort | Medium effort | High effort |
| `/simplify` | Skipped | Yes | Yes |
| `/security-review`, with its findings fixed | No | No | Yes, after the code review |

The agent runs the checks again and commits its fixes. Then Foreman runs its whole gate a second time, so a fix from the review can't sneak past it. Foreman stores the tier on the run and shows it on the dashboard and the run page. It also adds a `risk:tier-1`, `risk:tier-2` or `risk:tier-3` label to the PR. It creates the label first if the repository doesn't have it.

The review pass can end the run early:

- If the agent writes a `needs_human` result during the pass, the run goes to **Needs you** and nothing is pushed.
- If the pass stops with an error, such as running out of turns, the run fails and nothing is pushed.
- If `/security-review` ends without reviewing anything, the run fails. That command fails silently when it can't work out the diff.
- The pass shares the run's spend cap. It gets what the first part of the run left. If nothing is left, the run fails before the pass.

### Why review depth follows risk

A deep review on every change costs time and money for little gain. A typo fix in the docs doesn't need a security review. A new route or a raw SQL query does. The tier comes from rules that a program applies to the diff, not from the agent's own view of its work. So the agent can't talk its way into a lighter review. Reviews still find problems that rules miss, so every tier gets at least a low-effort code review.

### What it doesn't do

- The tier is only as good as the stack rules. Code that is risky but matches no pattern is tier 2.
- Path rules match anywhere in a path. So `routes/**` also matches `resources/js/routes/`. A change can be rated higher than it should be, but not lower.
- The tier comes from the committed diff before the review pass. The pass's own fixes don't change it.
- Each pass gets the repository's full turn and time limits again. Only the spend cap is shared across the run.
- `/security-review` diffs against `origin/HEAD`. When the repository has no `origin/HEAD`, Foreman points it at the base branch first, with `git remote set-head`. When `origin/HEAD` points at some other branch, Foreman leaves it alone and tells the agent which base to review against.
- Fix-up rounds on an open PR don't get a review pass.
