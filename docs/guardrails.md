# Guardrails

Checks that a program enforces stop bad pull requests (PRs). AI review helps, but it can't be the last word. Foreman treats the agent as capable but untrusted. Its work should only become a PR after Foreman has checked it with rules that fit the repository's languages. Issue text is input anyone can write, and agents sometimes delete or skip a failing test because that is a smaller edit than fixing the code. Guardrails catch these things mechanically.

This page explains each guardrail: what it does, why it exists, and what it doesn't catch. For the order they run in, see [How Foreman works](how-foreman-works.md). The rule tables for each language are in [Stack rules](stack-rules.md). Where the guardrails stop, see [Security and known gaps](security.md).

## The pipeline at a glance

| Step | What it does |
| --- | --- |
| Intake gate | Takes only issues that a trusted person marked as ready. See [Intake gate](#intake-gate). |
| Stack detection | Finds which languages a worktree uses, and loads each one's rules. See [Stack rules](stack-rules.md). |
| Sanitised issue | Cleans the issue text and hands it to the agent as untrusted data. See [Sanitised issue](#sanitised-issue). |
| Foreman pushes | The agent can't push or use `gh`. It writes a result file, and Foreman acts on GitHub. See [Foreman pushes, not the agent](#foreman-pushes-not-the-agent). |
| Check commands | Moves a run to **Running checks** when the agent runs a test, lint or type-check command, for PHP, TypeScript, Rust and Python. See [Runs and statuses](runs.md#the-stations). |
| Protected files and commands | A hook that blocks edits to protected files, and commands such as `git push` and `gh`. See [Protected files and commands](#protected-files-and-commands). |
| Test integrity | A script that fails when tests are deleted, weakened or skipped, or when tool settings are loosened. See [Test integrity](#test-integrity). |
| Definition of done | A Stop hook that sends the agent back until the verify script and the integrity check pass. See [Definition of done](#definition-of-done). |
| Commit-time formatting | A hook that formats the staged files when the agent runs `git commit`. See [Formatting at commit time](#formatting-at-commit-time). |
| Foreman's gate | Foreman runs the verify script and the integrity check again, outside any hook, and checks the size of the change before it pushes. See [Foreman's gate](#foremans-gate). |
| Risk tiers and review | Rates the change from its diff, then has the agent review it as deeply as the tier calls for. The gate runs again afterwards. See [Risk tiers](#risk-tiers). |
| Scope judge | A separate Haiku call with no tools reads only the issue and the diff, and vetoes changes that go beyond the issue or weaken tests. See [Scope judge](#scope-judge). |
| CI kit | A command that writes a CI workflow into your repository, with a job per stack. See [Setting up CI in your repo](ci-setup.md). |

## Sanitised issue

The agent doesn't read the issue itself. Foreman fetches the title and body, cleans them, and puts them in the prompt. A custom prompt can place them with the `{{issue_text}}` placeholder, listed in [Repository settings](repository-settings.md#instructions).

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
- The push block is a guardrail, not a sandbox. Allowed tools such as `npm`, `composer`, `cargo` and `python` run any code, and that code could set its own git configuration. The push URL and the missing tokens stop the agent's normal commands. See [Known gaps](security.md#known-gaps).
- The agent can still reach the network through allowed tools such as `npm` and `composer`.

### Rounds and resumes follow the same rules

[Fix-up rounds](pr-watching.md#fix-up-rounds) and **Continue after my reply** resume the agent's session, and they keep every rule above.

- **The agent still has no `gh` and no push.** It gets the same allowed tools as a first run. For a round, Foreman fetches the failed CI logs and the open bot threads. For a resume, it fetches the new issue comments. It puts them in the prompt, and pushes the round's commits itself.
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

Foreman finds a repository's languages, its *stacks*, from marker files, and loads each one's rules: protected config, lock files, test markers, formatters, default checks and risk patterns. The full tables are in [Stack rules](stack-rules.md).

## Protected files and commands

Every run starts Claude with its own settings file. The file adds a `PreToolUse` hook, which Claude runs before each `Edit`, `Write`, `MultiEdit`, `NotebookEdit` and `Bash` call. The guard is `php artisan foreman:guard`. It reads the tool call on its input and decides if it may go ahead. The same hook also runs the [commit-time formatter](#formatting-at-commit-time).

The settings file and its rules file are in `storage/foreman/hooks/`, outside the worktree, so Claude's edit tools don't touch them. They aren't fully safe, though. With the sandbox off, an allowed shell tool such as `php` or `python` can write there. See [Known gaps](security.md#known-gaps).

### What the hook blocks

| Blocked | Details |
| --- | --- |
| Stack config | Each stack's protected config from the [Stack rules](stack-rules.md) table, such as `phpunit.xml`, `phpstan.neon` and `tsconfig.json`. |
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
- Edits made by a shell command, such as `sed -i`, `echo >` or a `php` script, are not edits to the guard. It only sees `Edit`-style tools. The [test-integrity check](#test-integrity) looks at the finished diff, so it catches those edits to tests and tool settings. Nothing checks locked paths, lock files or `CLAUDE.md` afterwards, so shell edits to those get through.
- The skip-marker rule applies to every file, Markdown included. An `Edit` that quotes `.skip(` in a doc is blocked, even though the test-integrity check allows it.
- Paths are matched as written. A file reached through a symlink to a protected file isn't recognized.

## Formatting at commit time

When the agent runs `git commit`, Foreman first formats the staged files and stages them again. The commit then holds formatted code. It runs `php artisan foreman:format-staged` in the same `PreToolUse` hook as the guard above, and acts only on `Bash` calls.

For each stack, Foreman picks the formatter from the [Stack rules](stack-rules.md) table. It passes the staged files that the formatter handles, such as `.php` files for Pint or `.rs` files for `rustfmt`. Then it runs `git add` on those files.

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
| A deleted test file | Any file that matches the stack's test files in the [Stack rules](stack-rules.md) table. A moved test file counts as a delete. |
| Fewer tests or assertions | Per file, using the test and assertion markers in the table. A file that ends with fewer of either than it started with fails. Rust unit tests sit in source files, so every changed `.rs` file is counted. |
| A new skip or suppress marker | A file that holds more of a skip marker than before, such as `markTestSkipped`, `.skip(`, `#[ignore]`, `@phpstan-ignore` or `# noqa`. Markers that were already there pass. Markdown, text and JSON files are ignored, so docs can quote them. |
| An edit to protected config | Any change to a file in the table's protected config row, such as `phpunit.xml` or `tsconfig.json`. In `pyproject.toml` only the `[tool.*]` tables count. Other parts of the file can change. |
| A larger PHPStan baseline | A `phpstan*baseline*.neon` or `baseline.neon` file that holds more errors than before, counting each entry's `count:`. A smaller baseline passes, and the other PHPStan config is protected as above. |

A diff with none of these passes. Adding tests, adding assertions and fixing a baseline entry are all fine. The guard still blocks `Edit` and `Write` on `phpstan*.neon` files, baselines included, so the agent can only shrink a baseline from the shell.

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

1. The [test-integrity check](#test-integrity), against `origin/<base>`.
2. The repository's [verify script](repository-settings.md#verify-script).

If either fails, the agent can't stop. It gets a message with the end of the failing output, up to 3,000 characters per check, and goes back to work. If both pass, it stops.

### Why it gives up after 3 tries

Some failures the agent can't fix: a missing tool, a flaky test, a check that fails on the base branch too. A hook that blocks forever would burn money for nothing. Claude Code also has its own limit. After 8 blocked stops in a row it forces the agent to stop, with no say from Foreman. Three is well under that. The agent gets three chances to fix the failing checks, and Foreman decides when to stop instead of Claude Code.

After the third block, the next stop is let through. The run log says `Stop hook gave up after 3 blocked stops.` The count starts again each time Foreman starts the agent, including when a run resumes after a human reply. Foreman's own checks after the agent finishes catch a run that gave up, except in a fix-up round. See [Foreman's gate](#foremans-gate) and [Troubleshooting](troubleshooting.md#stop-hook-gave-up).

### What it doesn't do

- It does not stop the agent from opening a PR. It only holds back the end of the turn.
- If it can't read its rules, it lets the agent stop. Blocking forever would be worse. Its message goes to Claude, not to the run log, because the log's path is in the rules it couldn't read.
- The verify script is only as good as you make it. An empty script falls back to the detected stacks' checks, and a stack Foreman can't detect has none.

## Foreman's gate

When the agent says it is done with a `pr` result, Foreman doesn't push yet. The run moves to the **Reviewing** station. There Foreman runs these in the worktree:

1. The [test-integrity check](#test-integrity), against `origin/<base>`.
2. The repository's [verify script](repository-settings.md#verify-script). An empty script falls back to the detected stacks' checks, as in [Definition of done](#definition-of-done).
3. A size check. It adds up the lines added and deleted against the base branch, and compares the total with the size limit.

If all three pass, Foreman works out the [risk tier](#risk-tiers) and sends the agent through a review pass. Then the [scope judge](#scope-judge) checks that the change stays within the issue. If the judge passes it, Foreman runs all three checks again. Only if they pass a second time does Foreman push the branch and open the PR. It adds a footer to the PR body that lists the checks it ran, the integrity result, the size and the judge's verdict. If any one fails, nothing is pushed. The run ends as **Needs you**. Foreman comments on the issue with the output, up to 3,000 characters per check, and adds the human label. When you reply, **Continue after my reply** sends the agent back into the same worktree to fix it.

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

1. **Tier 3:** a changed file matches a high-risk path of a detected stack. Or an added line matches one of its risky-code patterns. The lists are in [Stack rules](stack-rules.md) and [PHP frameworks](stack-rules.md#php-frameworks). A renamed file counts under its old path too, so moving a file out of `routes/` is still tier 3.
2. **Tier 1:** every changed file is docs or tests, and the change is under 200 changed lines (added plus deleted). Docs are `*.md`, `*.mdx`, `*.rst` and anything under `docs/`. Tests are the stacks' test files.
3. **Tier 2:** everything else.

The run log names what set the tier, such as `Risk tier 3: touches routes/web.php.`

### What changes at each tier

Foreman resumes the agent's own session for the review pass. The agent keeps everything it learned while writing the change. The run stays on the **Reviewing** station.

| | Tier 1 | Tier 2 | Tier 3 |
| --- | --- | --- | --- |
| `/code-review`, with its fixes applied | Low effort | Medium effort | High effort |
| `/simplify`: cleanups, and reuse of code the repository already has | Yes | Yes | Yes |
| `/security-review`, with its findings fixed | No | No | Yes, after the code review |

The agent runs the checks again and commits its fixes. Then Foreman runs its whole gate a second time, so a fix from the review can't sneak past it. Foreman stores the tier on the run and shows it on the dashboard and the run page. It also adds a `risk:tier-1`, `risk:tier-2` or `risk:tier-3` label to the PR. It creates the label first if the repository doesn't have it.

The review pass can end the run early:

- If the agent writes a `needs_human` result during the pass, the run goes to **Needs you** and nothing is pushed.
- If the pass stops with an error, such as running out of turns, the run fails and nothing is pushed.
- If `/security-review` ends without reviewing anything, the run fails. That command fails silently when it can't work out the diff.
- The pass shares the run's spend cap. It gets what the first part of the run left. If nothing is left, the run fails before the pass.

### Why review depth follows risk

A deep review on every change costs time and money for little gain. A typo fix in the docs doesn't need a security review. A new route or a raw SQL query does. The tier comes from rules that a program applies to the diff, not from the agent's own view of its work. So the agent can't talk its way into a lighter review. Reviews still find problems that rules miss, so every tier gets at least a low-effort code review. Every tier also gets `/simplify`. Small changes are cheap to simplify, and test code is where copied setup and missed helpers pile up.

### What it doesn't do

- The tier is only as good as the stack rules. Code that is risky but matches no pattern is tier 2.
- Path rules match anywhere in a path. So `routes/**` also matches `resources/js/routes/`. A change can be rated higher than it should be, but not lower.
- The tier comes from the committed diff before the review pass. The pass's own fixes don't change it.
- Each pass gets the repository's full turn and time limits again. Only the spend cap is shared across the run.
- `/security-review` diffs against `origin/HEAD`. When the repository has no `origin/HEAD`, Foreman points it at the base branch first, with `git remote set-head`. When `origin/HEAD` points at some other branch, Foreman leaves it alone and tells the agent which base to review against.
- Fix-up rounds on an open PR don't get a review pass.

## Scope judge

After the review pass, and before [Foreman's gate](#foremans-gate) runs the second time, one more check asks a simple question: does the change stay within the issue? Foreman makes a separate call to Claude's Haiku model to answer it. The judge answers in JSON with three fields:

- `in_scope`: whether the change sticks to what the issue asks for. Unrelated features, refactors the issue didn't mention, and leaving out most of the work all count against it.
- `weakens_tests`: whether the change deletes, skips or loosens existing tests or tool settings so they pass more easily. New tests are fine.
- `reason`: one or two sentences on why.

### How it works

Foreman runs `claude -p` with `--tools ''`, so the judge has no tools at all. It can't read files, run commands or look anything up. It runs in a temporary folder of its own outside the repository, with no session to resume, no MCP servers and no user settings, so your hooks and plugins don't load. Foreman sends the prompt on standard input, because a large diff can be too long for a single command-line argument. Its prompt holds only two things: the [sanitised issue](#sanitised-issue) and the branch's diff against the base branch. Both are marked as untrusted data. Claude Code's `--json-schema` flag makes the answer fit the three fields.

What happens next depends on the verdict:

- **Pass.** The change is in scope and leaves tests alone. Foreman runs its gate a second time. If the gate passes, Foreman pushes, opens the PR, and adds the verdict and reason to the PR footer.
- **Veto.** The change is out of scope, or weakens tests, or both. Foreman resumes the agent's session once with the judge's reason, and asks it to correct the change. Then the judge looks at the corrected change. If the second verdict passes, the footer says `passed after one correction round`. If it vetoes again, nothing is pushed. The run goes to **Needs you**, and Foreman comments on the issue with the reason. See [Runs and statuses](runs.md#needs-you).
- **No usable answer.** The call failed, or its answer was missing a field. Foreman asks once more. If the second try fails too, nothing is pushed. The run goes to **Needs you**, and the comment quotes what went wrong each time. Foreman never treats a missing verdict as a pass.

The run log shows a veto as soon as it happens. A passing verdict is logged only after the second gate passes.

### Why it is a separate model call

The agent can't judge its own scope fairly. It has spent the whole run building a story about why each edit was needed. A review inside the same session reads that story too, and tends to agree with it. Scope creep and weakened tests are exactly the mistakes that come with a good-sounding reason.

The judge never sees that story. It gets no session, no tools and no access to the worktree, so it can't read the agent's notes or commit messages beyond the diff. It sees what a human reviewer would see first: what was asked, and what changed. The agent also can't reach the judge. Foreman makes the call in its own process, after the agent has stopped. Haiku keeps the check cheap and fast. The question is narrow, and the call is small.

### What it doesn't do

- It is an AI opinion, not a rule. It can pass a change that drifts, or veto one that is fine. [Test integrity](#test-integrity) is still the mechanical check for weakened tests.
- It only sees the diff and the issue. A change that matches the issue's words but misses its intent can pass.
- A very large diff makes the judge's job harder. The [size limit](#the-size-limit) keeps the diff in check.
- A global `CLAUDE.md` in the repository's Claude profile may still load. Leaving out settings doesn't cover memory files. Project files don't load, because the judge runs outside the repository.
- The judge runs before the second gate, so a veto doesn't waste a full gate run. The corrected change still goes through the gate before the push, but it doesn't get a second review pass. The risk tier stays as it was set before the correction.
- It only judges the first PR of a run. Fix-up rounds on an open PR don't get a judge.
- The judge's cost isn't added to the run's cost or counted against the spend cap. A Haiku call on a diff under the size limit costs a few cents at most.
