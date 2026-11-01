# Security and known gaps

Foreman runs an AI agent on your machine, against issue text that other people can write. This page says what protects you and where the protection stops. Read it before you widen a repository's allowed tools or point Foreman at a repository where outsiders can open issues.

## What protects you

- **There is no login.** The dashboard is meant for `localhost` only. Don't expose it to a network.
- **Only trusted people can queue work.** The [intake gate](guardrails.md#intake-gate) skips a ready label added by a bot or by someone without write access, and an issue edited after the label was added.
- **The agent doesn't act with your push and `gh` rights.** It commits in its worktree and writes a result file. Foreman reads that file, then pushes the branch and opens the PR itself. The agent's environment has no push URL, no git credential helper and no GitHub tokens. The default **allowed tools** list has no `gh` and no `git push`, and the guard blocks both anyway. See [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent).
- **Text from other people is marked as untrusted.** The issue, bot threads, CI logs and issue comments reach the agent wrapped as data, not instructions. This lowers the risk of prompt injection. It doesn't remove it. See [Sanitised issue](guardrails.md#sanitised-issue).
- **Foreman checks the work before it pushes.** See [How Foreman works](how-foreman-works.md).
- **You review every PR.** Foreman never merges.

## Known gaps

A *sandbox* is a limit the operating system puts on a command. It controls which files the command can write and which hosts it can call. Foreman's guardrails check the agent's work. They don't stop the code that allowed tools run, as [Foreman pushes, not the agent](guardrails.md#foreman-pushes-not-the-agent) explains. The [Sandbox](repository-settings.md#sandbox) setting narrows that gap, but doesn't close it. It is off by default.

### The setup script and the checks run unsandboxed

The [setup script](repository-settings.md#setup-script) and the [verify script](repository-settings.md#verify-script) run as you. That includes your keychain, the macOS store that holds the `gh` and git passwords. Foreman's gate and the [definition-of-done](guardrails.md#definition-of-done) hook run the verify script and the integrity check. So code the agent wrote, such as a new test, runs with your access whenever the checks run.

### Shell tools get past the edit rules

The [guard](guardrails.md#protected-files-and-commands) only checks Claude's edit tools. An allowed shell tool, such as `php`, `python` or an npm script, can write any file you can write while the sandbox is off. With the sandbox on, it can still write anywhere in the worktree. This means:

- **Locked paths, lock files and repository controls** such as `CLAUDE.md` can be changed from the shell. The test-integrity check only covers tests and tool settings, so nothing catches these changes before the PR.
- **Foreman's hook files** in `storage/foreman/hooks/` can be changed while the sandbox is off. A changed rules file changes what the guard and the Stop hook enforce for the rest of the run. Writing to the stop counter lets the agent stop with failing checks. [Foreman's gate](guardrails.md#foremans-gate) still checks a first PR, but fix-up rounds push without it.

Your review of the PR is the check for these. [Issue #61](https://github.com/lonnieezell/foreman/issues/61) tracks closing this gap.

### What the sandbox setting doesn't cover

The setting turns on Claude Code's own sandbox for the agent's shell commands. It was tested only on macOS, with Claude Code 2.1.316. [Issue #29](https://github.com/lonnieezell/foreman/issues/29) has the commands and results.

- **It doesn't fully protect your credentials.** Foreman blocks reads of `~/.config/gh`, so `gh auth token` fails. But a script can still read the `gh` token straight from the keychain, for example with `security find-generic-password`. Claude Code's sandbox profile for macOS allows keychain lookups, and its settings have no way to turn that off. The *allowlist*, the hosts a sandboxed command may reach, must include GitHub for git dependencies. So a script could still send the token there.
- **It covers only the agent's shell tool.** The setup script and Foreman's gate run outside Claude, so the sandbox never applies to them. Claude Code's hooks run outside it too. That keeps Foreman's hooks working, but the definition-of-done hook runs the checks unsandboxed.
- **A repository can probably widen it.** Claude Code's settings code merges lists such as the allowlist from every settings file. So hosts in the repository's own `.claude/settings.json` would be allowed too. This wasn't tested. The guard blocks Claude's edit tools on that file, but a shell tool can still write it, as described above.
- **It isn't tested on Linux or Windows.** Claude Code's sandbox works differently there. Foreman asks Claude Code to fail when no sandbox is available, but that path wasn't tested either.
- **Some of the `.git` folder stays writable.** Commits need to write objects, refs and logs there. The hooks, the config, and the files that point a worktree at its git folder stay blocked. Those would let a script run code through Foreman's own git commands, outside the sandbox. A test on macOS confirmed that writes to the hooks, the config and those files were denied, while commits still worked.
