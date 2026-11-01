# Troubleshooting

## Problems at the top of the dashboard

The dashboard checks that everything Foreman depends on is there. It lists any problem at the top in red, with a fix. Click **Check again** after fixing something.

| Problem | Fix |
| --- | --- |
| git isn't on the PATH of the process running Foreman. | Start Foreman from a terminal where `git --version` works. |
| GitHub CLI isn't installed or isn't logged in. | Run `gh auth login`, then restart Foreman. |
| Claude Code isn't on the PATH. | Start Foreman from a terminal where `claude --version` works. |
| The scheduler isn't running, so no repositories are being checked. | Start everything with `composer run foreman`, not just `php artisan serve`. |
| Run(s) have been waiting over two minutes for a worker. | See [Runs stay on Waiting](#runs-stay-on-waiting). |

Foreman remembers the tool checks for five minutes. So a fixed problem can take a moment to clear unless you click **Check again**.

## A run failed

Open the run. The error is in red near the top. Foreman also posts it as a comment on the issue and adds the human label. The worktree is kept, and its path is shown above the log. Open that folder to see exactly what the agent left behind.

Common errors:

| Error | What it means |
| --- | --- |
| "Running setup script failed: …" | The setup script hit an error in the fresh worktree. The message has the script's output. Fix the script in the repository's settings. |
| "Fetching origin failed: …" or "Creating worktree … failed: …" | git couldn't fetch or make the worktree. Check that the local checkout's remote works, and that the base branch exists on `origin`. |
| "Hit the 45-minute limit." | The agent ran out of time. Split the issue up, or raise **Max minutes per issue**. |
| "Claude stopped with an error (…)." | Claude ended the run with an error. The part in brackets says which. `error_max_turns` means it ran out of turns: split the issue up, or raise **Max turns per issue**. |
| "The agent finished without opening a PR or flagging the issue." | Claude exited normally but left no PR and no human label. Its last message follows the error and usually says why. |
| "Claude exited without a result: …" | Claude crashed or couldn't start. The message has its error output. |
| "The worker stopped responding. It was probably restarted mid-run." | No heartbeat for 5 minutes, usually because the laptop slept or Foreman was stopped. Foreman only releases the issue in this case. It doesn't comment or add the human label. |

When you've dealt with the cause, click **Start over** on the run page, or **Retry** on the dashboard. That queues the issue again with a fresh worktree.

If Claude stopped because a usage limit was hit, see [Usage limits](usage-limits.md#if-a-run-hits-a-limit).

## Runs stay on Waiting

`composer run foreman` starts three workers. A run waits on **Waiting** until one is free. If all three are busy, or none are running, add a worker in another terminal:

```bash
php artisan queue:work --queue=runs --timeout=0
```

A repository's **Issues at a time** only helps up to the number of workers.

## A repository shows "Last check failed"

The last `gh` call for that repository failed. The message on its row says why. Common causes are an expired `gh` login, a renamed repository, or no network. Foreman tries again on the next check.

## Nothing is being picked up

Check these in order:

1. Is the repository started? Its row shows **Stop** when it's running.
2. Is Foreman paused? The stats row says **Paused**.
3. Is a daily limit reached? Look at today's spend and the repository's row.
4. Is its profile held? Look for a **Held** gauge and a notice.
5. Does the issue have exactly the ready label, and is it open?
6. Is the issue blocked by another open issue? Foreman skips blocked issues.
7. Are all the repository's slots in use?
8. Is the issue listed as skipped on the repository's row? See [Issue skipped by intake gate](#issue-skipped-by-intake-gate).

## "Issue skipped by intake gate"

The repository's row lists the issue under **Skipped by the intake gate**, with a reason. Foreman only takes issues that a trusted person marked as ready. There are four reasons:

- **The label was added by a bot.** Ask a person with write access to remove the label and add it again.
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
