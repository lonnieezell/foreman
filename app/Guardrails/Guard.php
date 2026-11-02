<?php

namespace App\Guardrails;

/**
 * Decides whether one tool call from the agent is allowed. Used by the PreToolUse hook.
 *
 * `check()` returns the reason a call is blocked, or null when it may go ahead.
 */
class Guard
{
    /** A command starts at the beginning, or after ; & | ( a backtick or a newline, past any VAR=value prefixes. */
    public const COMMAND_START = '(?:^|[;&|(`\n])\s*(?:\w+=\S*\s+)*';

    private const HOOKS_BLOCKED = "Foreman's hook files are off limits. They hold the rules this guard enforces.";

    private const EDIT_TOOLS = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];

    /** Protected in every repository, whatever the stack. Matched against the file name or the end of the path. */
    private const ALWAYS_PROTECTED = ['.github/workflows/**', 'CLAUDE.md', 'AGENTS.md', '.claude/**', '.env', '.env.*'];

    /** Env file templates hold no secrets, and a repo's setup script copies them. */
    private const ALLOWED = ['.env.example'];

    /**
     * @param  list<string>  $lockedPaths  Globs from the repository's "Locked paths" setting, relative to the worktree root.
     */
    public function __construct(
        private StackSet $stacks,
        private string $worktree,
        private array $lockedPaths = [],
        private bool $allowLockFiles = false,
        private string $hooksDir = '',
    ) {}

    /**
     * The reasons any of these changed files, relative to the worktree, break a locked path or lock file rule.
     * Foreman's gate runs this against the branch diff, because shell tools can write files the edit checks never see.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function lockedChanges(array $paths): array
    {
        return array_values(array_filter(array_map($this->lockedReason(...), $paths)));
    }

    public function check(array $hook): ?string
    {
        $tool = $hook['tool_name'] ?? '';
        $input = $hook['tool_input'] ?? [];

        if ($tool === 'Bash') {
            return $this->checkCommand((string) ($input['command'] ?? ''));
        }

        if (! in_array($tool, self::EDIT_TOOLS, true)) {
            return null;
        }

        $file = (string) ($input['file_path'] ?? $input['notebook_path'] ?? '');

        if ($this->inHooksDir($file)) {
            return self::HOOKS_BLOCKED;
        }

        $path = $this->relativePath($file);

        return $path === null ? null : $this->checkEdit($path, $input);
    }

    private function checkCommand(string $command): ?string
    {
        $start = self::COMMAND_START;

        return match (true) {
            $this->mentionsHooksDir($command) => self::HOOKS_BLOCKED,
            (bool) preg_match("/{$start}git(?:\\s+-[\\w-]+(?:[ =]\\S+)?)*\\s+push\\b/", $command) => 'git push is blocked. Foreman pushes the branch after its own checks.',
            (bool) preg_match("/{$start}gh(?:\\s|\$)/", $command) => 'gh commands are blocked. Foreman talks to GitHub, not the agent.',
            (bool) preg_match('/(?:^|\s)--no-verify\b/', $command) => '--no-verify is blocked. Fix what the hooks complain about instead of skipping them.',
            default => null,
        };
    }

    private function checkEdit(string $path, array $input): ?string
    {
        if (in_array(basename($path), self::ALLOWED, true)) {
            return null;
        }

        if ($this->matchesAny(self::ALWAYS_PROTECTED, $path, anywhere: true)) {
            return "{$path} is protected. CI workflows, CLAUDE.md, AGENTS.md, .claude/ and .env files are off limits.";
        }

        if ($this->matchesAny($this->stacks->rules('protectedConfig'), $path, anywhere: true)) {
            return "{$path} holds tool settings. Change the code to satisfy the tool, not the tool's settings.";
        }

        if ($reason = $this->lockedReason($path)) {
            return $reason;
        }

        return $this->addsSkipMarker($input)
            ? 'This edit adds a skip or suppress marker. Fix the code or the test instead of silencing it.'
            : null;
    }

    private function lockedReason(string $path): ?string
    {
        if ($this->matchesAny($this->lockedPaths, $path, anywhere: false)) {
            return "{$path} is locked in this repository's settings.";
        }

        if (! $this->allowLockFiles && $this->matchesAny($this->stacks->rules('lockFiles'), $path, anywhere: true)) {
            return "{$path} is a lock file. It may only change when the issue has the `dependencies` label.";
        }

        return null;
    }

    private function mentionsHooksDir(string $command): bool
    {
        return array_filter($this->hooksDirs(), fn ($dir) => str_contains($command, $dir)) !== [];
    }

    private function inHooksDir(string $path): bool
    {
        return array_filter($this->hooksDirs(), fn ($dir) => str_starts_with($path, "{$dir}/")) !== [];
    }

    /** The hooks directory as given and with symlinks resolved, without a trailing slash. Empty when none is set. */
    private function hooksDirs(): array
    {
        if ($this->hooksDir === '') {
            return [];
        }

        return array_values(array_unique(array_filter([rtrim($this->hooksDir, '/'), rtrim((string) realpath($this->hooksDir), '/')])));
    }

    /** True when the edit leaves more of some marker in the file than it took out. */
    private function addsSkipMarker(array $input): bool
    {
        $pairs = isset($input['edits'])
            ? array_map(fn ($e) => [$e['old_string'] ?? '', $e['new_string'] ?? ''], (array) $input['edits'])
            : [[$input['old_string'] ?? '', $input['new_string'] ?? $input['content'] ?? $input['new_source'] ?? '']];

        foreach ($pairs as [$old, $new]) {
            foreach ($this->stacks->rules('skipMarkers') as $marker) {
                if (substr_count((string) $new, $marker) > substr_count((string) $old, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The path relative to the worktree, or null when the file is outside it. */
    private function relativePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            return ltrim((string) preg_replace('#^(\./)+#', '', $path), '/');
        }

        foreach (array_unique([$this->worktree, (string) realpath($this->worktree)]) as $root) {
            if ($root !== '' && str_starts_with($path, rtrim($root, '/').'/')) {
                return substr($path, strlen(rtrim($root, '/')) + 1);
            }
        }

        return null;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(array $patterns, string $path, bool $anywhere): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            $pattern = str_ends_with($pattern, '/') ? "{$pattern}**" : $pattern;

            if (preg_match(($anywhere ? '#(?:^|/)' : '#^').self::globToRegex(ltrim($pattern, '/')).'$#', $path)) {
                return true;
            }
        }

        return false;
    }

    private static function globToRegex(string $glob): string
    {
        return strtr(preg_quote($glob, '#'), ['\*\*/' => '(?:.*/)?', '\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']);
    }
}
