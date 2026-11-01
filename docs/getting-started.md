# Getting started

This page takes you from an empty dashboard to your first pull request (PR) opened by the agent.

Foreman has to be running first. Start it with `composer run foreman` from a terminal where `gh auth status` and `claude --version` both work. If something is missing, the dashboard lists the problem at the top. See [Troubleshooting](troubleshooting.md#problems-at-the-top-of-the-dashboard).

## 1. Add a repository

Click **Add repository** on the dashboard.

- **Local checkout** is a folder on this machine that holds a git clone. Click **Browse…** to pick it, or type the path. `~/` works.
- Click **Detect** to fill in the **GitHub repository** (`owner/name`) and the **Name in Foreman**. Browse runs Detect for you.
- The other settings have sensible defaults. You can change them later. See [Repository settings](repository-settings.md).

The **Setup script** is the one setting to check now. It runs in every fresh worktree before the agent starts. It must get the project ready to build and test, for example by copying `.env` and installing dependencies. Not sure what it needs? Click the small code icon (`< >`) next to its label. That copies a prompt you can paste into `claude` inside the project, and Claude works the script out for you.

Click **Add repository** to save. Foreman creates its three labels on GitHub if they don't exist yet: `ready-for-agent`, `agent-working` and `needs-human`.

## 2. Pick an issue

Choose a small issue with a clear spec. The issue body *is* the spec. The agent stays strictly inside it and doesn't refactor anything else.

Add the `ready-for-agent` label to the issue. Only labels added by people with write access to the repository count. Foreman skips a label added by a bot or by someone without write access. It also skips an issue that was edited after the label was added, so add the label last. See [Intake gate](guardrails.md#intake-gate).

Foreman also skips issues that are blocked by other open issues. It takes ready issues oldest first.

## 3. Start the repository

A new repository starts stopped. Click **Start** on its row. Foreman checks for ready issues straight away. After that it checks on a timer, every 10 minutes by default. A small countdown on the row shows when the next check is due. Click the round arrow button to check now.

## 4. Follow the run

The issue appears as a yellow tag on the repository's track. It moves through four stations: **Waiting**, **Setting up**, **Writing code** and **Running checks**. Click the tag to open the run. The run page shows a live log of what the agent is doing.

See [Runs and statuses](runs.md) for what each station means.

## 5. Review the result

When the run ends, the issue moves to the **Issues** column on the dashboard. Usually it lands in one of these sections:

- **Ready for review**: there's a PR. Click **Open PR** to review it.
- **Needs your reply**: the agent got stuck and asked a question on the issue. Reply there, then click **Continue after my reply**.
- **Failed**: something went wrong. Open the run to see the error. See [A run failed](troubleshooting.md#a-run-failed).

By default Foreman also watches each new PR. It waits for CI and review bots, then fixes failing checks and answers bot comments. See [PR watching](pr-watching.md).

## Next steps

- Let more issues run at once with the **−** / **+** buttons on the repository's row.
- Set a daily spend limit, for one repository or for all of them. See [Global settings](global-settings.md#spend).
- On a Claude subscription, read [Usage limits and profiles](usage-limits.md). It explains why Foreman sometimes holds new runs.
