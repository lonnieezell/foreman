<?php

/*
 * Claude Code profiles, as "name:config-dir" pairs separated by commas, e.g.
 * CLAUDE_PROFILES="personal:~/.claude-personal,work:~/.claude-work"
 * A repository on a profile runs Claude with CLAUDE_CONFIG_DIR set to that folder.
 * Repositories without one use the default ~/.claude login.
 */
$profiles = [];

foreach (array_filter(array_map('trim', explode(',', (string) env('CLAUDE_PROFILES', '')))) as $pair) {
    [$name, $dir] = array_map('trim', explode(':', $pair, 2)) + [1 => ''];

    if ($name !== '' && $dir !== '') {
        $profiles[$name] = str_starts_with($dir, '~/') ? rtrim((string) getenv('HOME'), '/').substr($dir, 1) : $dir;
    }
}

return [
    'claude_profiles' => $profiles,

    /*
     * When to hold new runs on a profile, from its /usage numbers (0–1).
     * The weekly window is paced: hold once usage is more than the margin ahead
     * of how much of the week has gone, or at the cap whatever the pace.
     */
    'usage' => [
        'five_hour_ceiling' => (float) env('FOREMAN_USAGE_FIVE_HOUR_CEILING', 0.80),
        'weekly_pace_margin' => (float) env('FOREMAN_USAGE_WEEKLY_PACE_MARGIN', 0.10),
        'weekly_cap' => (float) env('FOREMAN_USAGE_WEEKLY_CAP', 0.90),
        'probe_minutes' => (int) env('FOREMAN_USAGE_PROBE_MINUTES', 15),
    ],

    /*
     * Foreman's gate before it pushes. A branch that changes more lines than this
     * (added plus deleted, against the base branch) goes to "Needs you" instead of opening a PR.
     */
    'max_pr_lines' => (int) env('FOREMAN_MAX_PR_LINES', 1000),

    /*
     * Watching a PR after it opens. A round starts once no check is pending and
     * review bots have been quiet for quiet_minutes, or timeout_minutes after the push.
     * A PR no bot has posted on is done after first_look_minutes instead.
     */
    'pr_watch' => [
        'max_rounds' => (int) env('FOREMAN_PR_MAX_ROUNDS', 2),
        'first_look_minutes' => (int) env('FOREMAN_PR_FIRST_LOOK_MINUTES', 1),
        'quiet_minutes' => (int) env('FOREMAN_PR_QUIET_MINUTES', 5),
        'timeout_minutes' => (int) env('FOREMAN_PR_TIMEOUT_MINUTES', 30),
    ],
];
