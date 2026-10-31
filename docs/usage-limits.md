# Usage limits and profiles

On a Claude subscription, Foreman's runs share your usage limits with your own Claude sessions. These are the limits you see with `/usage`. Foreman watches them and holds new runs before you run out, so your interactive sessions keep working.

## Claude profiles

If you use more than one Claude Code login, for example a personal and a work account, define each one as a *profile* in `.env`:

```dotenv
CLAUDE_PROFILES="personal:~/.claude-personal,work:~/.claude-work"
```

Each entry is `name:config-folder`, separated by commas. Restart Foreman after changing it.

- A repository on a profile runs Claude with `CLAUDE_CONFIG_DIR` set to that folder.
- Usage numbers, holds, gauges and token totals are all tracked per profile.
- With profiles defined, each repository must pick one. It starts on the first.
- With no profiles defined, every repository uses your normal `~/.claude` login. Foreman calls that the `default` profile.

When repositories use more than one profile, the dashboard groups them by profile.

## The gauges

The stats row has a gauge for each profile, with two bars:

- **5h** is the rolling 5-hour window.
- **week** is the 7-day window.

The small tick on each bar marks where Foreman holds runs. On the 5h bar that's the 5-hour ceiling. On the week bar it's the weekly pace for this point in the week, explained below. Hover a bar to see the exact numbers and when the window resets.

A bar turns amber within 10 points of its tick, and red at or past it. **Held** appears next to the profile name while new runs are held. The line under the gauge says how old the numbers are, and how many tokens the profile used today.

"usage unknown" means Foreman has no numbers for that profile yet. That's normal until its first run or check.

## Where the numbers come from

Claude Code reports usage while it works. Foreman saves the latest numbers for each profile from every run.

Numbers can go stale while nothing runs. So before starting runs on a profile, Foreman checks how old its numbers are. If they're older than **Refresh usage after** (15 minutes by default), it sends a *probe*. A probe is the cheapest possible Claude call: one turn of Haiku with no tools, run outside any project. Foreman only probes when a repository on that profile actually has ready issues waiting.

## When runs are held

Foreman holds new runs on a profile when any of these is true:

- The 5-hour window is at or above the **5-hour ceiling** (80%).
- The weekly window is at or above the **weekly cap** (90%).
- The weekly window is ahead of pace. *Pace* is how much of the week has passed, plus the **weekly pace margin** (10%). It never goes above the cap.
- Claude reported that a limit was hit and that window hasn't reset yet.

Here's a pace example. Two days into a seven-day week, about 29% of the week has passed. With the 10% margin, the pace is about 39%. If weekly usage is at 45%, Foreman holds until time catches up or the window resets.

While a profile is held:

- The dashboard shows a notice with the reason, like "5-hour usage at 85%, resets 3:40pm."
- Repositories on that profile claim no new issues. Their issues stay labelled ready on GitHub.
- Fix-up rounds on that profile wait too.
- Runs already in progress keep going.

The hold lifts by itself once the numbers allow it, usually when a window resets.

You can change the thresholds on the **Settings** page. See [Global settings](global-settings.md#usage-holds).

### If a run hits a limit

If Claude stops mid-run because of a usage limit, Foreman holds the profile instead of moving on to the next issue. Otherwise every queued issue would fail in turn. The run itself fails. Use **Retry** once the limit resets.

## Run anyway

The hold notice has a **Run anyway** button. It lets the next check start runs on that profile, whatever the numbers say. It applies to one check only, then normal holds return. Use it when you know you have room, for example right after a window reset that Foreman hasn't seen yet.
