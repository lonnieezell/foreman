# Global settings

Some controls apply to all repositories at once. A few are on the dashboard. The rest are on the **Settings** page, linked from the header.

## On the dashboard

### Pause Foreman

The button at the right of the stats row. While paused, Foreman claims no new issues and starts no fix-up rounds. Runs in progress finish. PRs are still watched, because watching costs nothing. **Resume Foreman** turns it back on.

To stop just one repository, click **Stop** on its row instead.

### Run anyway

Shows in the notice when a Claude profile is held for usage. The next check then starts runs on that profile, whatever the usage numbers say. It works for one check only. See [Usage limits](usage-limits.md#run-anyway).

### Today's spend

The stats row shows today's spend across all repositories, next to the daily limit if you set one.

## On the Settings page

Changes on the Settings page reach running workers before their next job. You don't need to restart Foreman.

### Spend

**Daily limit across all repositories (USD).** Once today's spend reaches this, no new runs or fix-up rounds start. Empty means no limit. Each repository can also have its own limit. See [Repository settings](repository-settings.md#daily-limit-usd).

"Today" starts at midnight in the app's timezone. A run's cost counts toward the day the run was queued.

### Notifications

**Desktop notifications.** Get a browser notification when an issue needs your reply, fails, or is ready for review. Click the notification to open the run.

- It only works while a Foreman dashboard tab is open somewhere. The dashboard is what checks for changes.
- Turning it on asks the browser for permission. If the browser blocks notifications, the page tells you how to allow them.

### Usage holds

When to hold new runs on a Claude profile, based on its usage numbers. Leave a field empty to use the default shown in it.

| Field | Default | Meaning |
| --- | --- | --- |
| 5-hour ceiling (%) | 80 | Hold once the 5-hour window reaches this. |
| Weekly pace margin (%) | 10 | Hold once weekly usage is this far ahead of how much of the week has gone. |
| Weekly cap (%) | 90 | Hold at this weekly usage, whatever the pace. |
| Refresh usage after (minutes) | 15 | Check a profile's usage again before starting runs if the numbers are older than this. |

See [Usage limits and profiles](usage-limits.md) for how these work together.

### PR watching

For repositories with **Watch PRs** on. Leave a field empty to use the default shown in it.

| Field | Default | Meaning |
| --- | --- | --- |
| Fix-up rounds per PR | 2 | Rounds before the PR is handed to you. 0 hands it over straight away. |
| Review bots quiet for (minutes) | 5 | A round starts once no check is pending and bots have been quiet this long. |
| Start a round anyway after (minutes) | 30 | Measured from the push, even if checks are still pending. |

See [PR watching](pr-watching.md).

## Environment variables

Foreman's own settings live in `.env`. The `FOREMAN_*` values are the defaults the Settings page shows. A value saved on the Settings page wins over `.env`. Change `.env` only when you want a different default, then restart Foreman.

| Variable | Default | Settings page field |
| --- | --- | --- |
| `CLAUDE_PROFILES` | empty | none. See [Claude profiles](usage-limits.md#claude-profiles). |
| `FOREMAN_USAGE_FIVE_HOUR_CEILING` | `0.80` | 5-hour ceiling |
| `FOREMAN_USAGE_WEEKLY_PACE_MARGIN` | `0.10` | Weekly pace margin |
| `FOREMAN_USAGE_WEEKLY_CAP` | `0.90` | Weekly cap |
| `FOREMAN_USAGE_PROBE_MINUTES` | `15` | Refresh usage after |
| `FOREMAN_PR_MAX_ROUNDS` | `2` | Fix-up rounds per PR |
| `FOREMAN_PR_FIRST_LOOK_MINUTES` | `1` | none |
| `FOREMAN_PR_QUIET_MINUTES` | `5` | Review bots quiet for |
| `FOREMAN_PR_TIMEOUT_MINUTES` | `30` | Start a round anyway after |

The usage values in `.env` are fractions, so `0.80` means 80%. The Settings page takes percentages.

`FOREMAN_PR_FIRST_LOOK_MINUTES` is how long to wait on a PR that no review bot has posted on. It's only set in `.env`.

Everything else in `.env` is standard Laravel.
