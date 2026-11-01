# Troubleshooting

## Problems at the top of the dashboard

The dashboard checks that everything Foreman depends on is there. It lists any problem at the top in red, with a fix. Click **Check again** after fixing something.

| Problem | Fix |
| --- | --- |
| git isn't on the PATH of the process running Foreman. | Start Foreman from a terminal where `git --version` works. |
| GitHub CLI isn't installed or isn't logged in. | Run `gh auth login`, then restart Foreman. |
| Claude Code isn't on the PATH. | Start Foreman from a terminal where `claude --version` works. |
| The scheduler isn't running, so no repositories are being checked. | Start everything with `composer run foreman`. `php artisan serve` and `composer run dev` don't start the scheduler. |
| *N* run(s) have been waiting over two minutes for a worker. | See [Runs stay on Waiting](#runs-stay-on-waiting). |

Foreman remembers the tool checks for five minutes. So a fixed problem can take a moment to clear unless you click **Check again**.

## A run failed

Open the run. The error is in red near the top. Foreman also posts it as a comment on the issue and adds the human label. The worktree is kept, and its path is shown above the log. Open that folder to see exactly what the agent left behind.

Common errors:

| Error | What it means |
| --- | --- |
| "Running setup script failed: …" | The setup script hit an error in the fresh worktree. The message has the script's output. Fix the script in the repository's settings. |
| "Fetching origin failed: …" or "Creating worktree … failed: …" | git couldn't fetch or make the worktree. Check that the local checkout's remote works, and that the base branch exists on `origin`. |
| "Hit the 45-minute limit." | The agent ran out of time. Split the issue up, or raise **Max time per issue**. |
| "Claude stopped with an error (…)." | Claude ended the run with an error. The part in brackets says which. `error_max_turns` means it ran out of turns: split the issue up, or raise **Max turns per issue**. |
| "The agent finished without a readable result file (.foreman/result.json)." | Claude exited normally, but the result file is missing, isn't valid JSON, or lacks a field its outcome needs. Its last message follows the error and usually says why. See [How a run ends](runs.md#how-a-run-ends). |
| "Claude exited without a result: …" | Claude crashed or couldn't start. The message has its error output. |
| "The review pass stopped with an error (…).", "The correction round stopped with an error (…)." or "/security-review stopped with an error (…)." | A pass after the gate ended with an error, such as running out of turns. Nothing was pushed. See [Risk tiers](guardrails.md#risk-tiers). |
| "/security-review ended without reviewing anything." | `/security-review` couldn't work out the diff, so it did nothing. Check that the base branch exists on `origin`. |
| "The run used up its spend cap of $… before Foreman could resume the agent." | The **Per run** budget was used up before the review pass or a correction could start. Raise the limit, or use **Start over**. |
| "Couldn't diff the change for the scope judge: …" | git couldn't diff the branch against `origin/<base>` for the [scope judge](guardrails.md#scope-judge). |
| "Pushing … failed: …" | Foreman's push was refused. Check your git access to the repository, and whether the branch already exists on GitHub. |
| "Signing the agent's commits failed: …" | Your git config signs commits, and signing failed. Check that `gpg-agent` is running and your key is unlocked. See [Sandbox](repository-settings.md#sandbox). |
| "The worker stopped responding. It was probably restarted mid-run." | No heartbeat for 5 minutes, usually because the laptop slept or Foreman was stopped. Foreman only releases the issue in this case. It doesn't comment or add the human label. |

When you've dealt with the cause, click **Start over** on the run page, or **Retry** on the dashboard. That queues the issue again with a fresh worktree.

If Claude stopped because a usage limit was hit, see [Usage limits](usage-limits.md#if-a-run-hits-a-limit).

## "Blocked by guardrail" in the log

A line like `Blocked by guardrail: …` means a Foreman hook refused one of the agent's tool calls. The rest of the line says why. The run carries on, and the agent sees the same reason and can try something else.

| Reason starts with | What it means |
| --- | --- |
| "… is protected" | The agent tried to edit a CI workflow, `CLAUDE.md`, `AGENTS.md`, `.claude/` or an `.env` file. |
| "… holds tool settings" | The agent tried to change a linter, formatter, type-checker or test config. |
| "… is locked in this repository's settings" | The path matches **Locked paths** in the [repository settings](repository-settings.md#locked-paths). |
| "… is a lock file" | The agent tried to change a lock file. Add the `dependencies` label to the issue if it should. |
| "git push is blocked", "gh commands are blocked", "--no-verify is blocked" | The agent tried a command the agent may not run. |
| "commit blocked, the formatter failed" | The commit-time formatter failed on a staged file, usually a syntax error. The agent sees the output and fixes the file. See [Formatting at commit time](guardrails.md#formatting-at-commit-time). |
| "This edit adds a skip or suppress marker" | The agent tried to silence a test or tool instead of fixing the cause. |

A run that keeps hitting the same block usually can't finish. If the block is wrong for this issue, fix the cause: add the `dependencies` label, or remove the pattern from **Locked paths**, then use **Start over**. See [Guardrails](guardrails.md#protected-files-and-commands) for the full list.

If every edit and shell call is refused, the guard may not be able to read its rules. Its message, "Foreman guard could not read its rules or the tool call.", goes to the agent, not to the run log. Check that the files in `storage/foreman/hooks/` exist and are readable, then start the run over.

## "Stop hook gave up"

The run log shows `Blocked the stop (1 of 3): Verify script failed.` when the agent tried to finish while a check failed. The agent is sent back with the end of the failing output and keeps working. After the third block, the log shows `Stop hook gave up after 3 blocked stops. The checks still fail, so a human should look.` The agent is then let go, even though its work doesn't pass.

Why it gives up: a failure the agent can't fix would otherwise loop until Claude Code forces a stop. See [Definition of done](guardrails.md#definition-of-done).

What to do:

1. Open the run and read the log lines before the last block. They name the check that failed: **Verify script** or **Test-integrity check**.
2. Run the same check in the kept worktree. For the verify script, use the commands in the repository's [settings](repository-settings.md#verify-script).
3. If the script is wrong, for example it needs a tool the setup script doesn't install, fix it in the settings.
4. If the agent's work is wrong, fix it by hand or comment on the issue, then use **Start over**.
5. If the integrity check blocked a change on purpose, such as removing a test that no longer applies, make that change yourself.

The run isn't flagged for a human by this line alone. [Foreman's gate](guardrails.md#foremans-gate) runs the same checks again after the agent finishes, and sends a failing run to **Needs you**. Fix-up rounds are the exception: they skip the gate, so a round that gave up still pushes. Watch for this line on rounds.

If the Stop hook can't read its rules, it lets the agent stop. Its message goes to Claude, not to the run log, because the hook can't find the log without its rules. Check that the files in `storage/foreman/hooks/` exist, then start the run over.

## Runs stay on Waiting

`composer run foreman` starts three workers. A run waits on **Waiting** until one is free. If all three are busy, or none are running, add a worker in another terminal:

```bash
php artisan queue:work --queue=runs --timeout=0
```

A repository's **Issues at a time** only helps up to the number of workers.

## A repository shows "Last check failed"

Foreman couldn't list the repository's ready issues, or couldn't claim one. The message on its row says why. Common causes are an expired `gh` login, a renamed repository, or no network. Foreman tries again on the next check.

## Nothing is being picked up

Check these in order:

1. Is the scheduler running? If not, the dashboard says so at the top. See [Problems at the top of the dashboard](#problems-at-the-top-of-the-dashboard).
2. Is the repository started? Its row shows **Stop** when it's running.
3. Is a check due? Each repository is checked on its own timer, every 10 minutes by default. The countdown on its row shows when. Click the round arrow button to check now.
4. Is Foreman paused? The stats row says **Paused**.
5. Is a daily limit reached? Look at today's spend and the repository's row.
6. Is its profile held? Look for a **Held** gauge and a notice.
7. Does the issue have exactly the ready label, and is it open?
8. Is the issue blocked by another open issue? Foreman skips blocked issues.
9. Are all the repository's slots in use?
10. Is the issue listed as skipped on the repository's row? See [Issue skipped by intake gate](#issue-skipped-by-intake-gate).

## "Issue skipped by intake gate"

The repository's row lists the issue under **Skipped by the intake gate**, with a reason. Foreman only takes issues that a trusted person marked as ready. There are four reasons:

- **The label was added by a bot.** Ask a person with write access to remove the label and add it again. Or, to trust all bot labels, turn on **Trust ready labels added by bots** on the Settings page.
- **The label was added by someone without write access.** Ask a person with write access to remove it and add it again.
- **The issue was edited after the label was added.** Read the changes. If they are fine, remove the label and add it again. That counts as a fresh approval.
- **GitHub has no record of who added the label.** This can happen on a very old issue with a long history. Remove the label and add it again.

The list clears on the next check once nothing is skipped. See [Intake gate](guardrails.md#intake-gate).

## "Saved, but the labels couldn't be created on GitHub"

The settings saved, but `gh label create` failed. Usually your login can't write to that repository. Create the three labels by hand, or fix access and save again.

## Updating Foreman

After pulling new changes, run `composer run update-app`. Then stop `composer run foreman` with Ctrl+C and start it again. The workers keep running the old code until they restart.

## Files on disk

Run files are kept under `storage/foreman/`:

- `logs/run-<id>.log`: the readable log shown on the run page.
- `logs/run-<id>.jsonl`: Claude's raw `stream-json` output, for debugging.
- `worktrees/<owner>-<name>/issue-<n>/`: worktrees. Failed runs keep theirs here.
