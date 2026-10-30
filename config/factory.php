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
];
