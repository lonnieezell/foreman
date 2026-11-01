<?php

namespace App\Guardrails;

use Illuminate\Support\Facades\Process;

/**
 * Claude Code's sandbox for the agent's shell commands and the build scripts they start, when a repository
 * turns it on. Tested only on macOS. See docs/repository-settings.md#sandbox for what it doesn't cover.
 */
class Sandbox
{
    /** For git dependencies and release downloads, on top of the stacks' registries. */
    private const GITHUB_HOSTS = ['github.com', '*.github.com', '*.githubusercontent.com'];

    /** Folders under your home that hold keys and tokens. The keychain stays readable. */
    private const SECRET_DIRS = ['.gnupg', '.ssh', '.config/gh'];

    /** The `sandbox` block for the run's settings file. */
    public static function settings(string $worktree): array
    {
        [$gitDir, $commonDir] = explode("\n", trim(Process::path($worktree)
            ->run(['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'])->throw()->output()));
        $home = rtrim((string) getenv('HOME'), '/');
        $cargo = getenv('CARGO_HOME') ?: "{$home}/.cargo";

        return [
            'enabled' => true,
            'failIfUnavailable' => true,
            'allowUnsandboxedCommands' => false,
            'network' => ['allowedDomains' => [...self::GITHUB_HOSTS, ...StackSet::detect($worktree)->rules('networkHosts')]],
            'filesystem' => [
                // What a commit writes, but not the hooks or the config: Foreman's own git commands would run or read them unsandboxed
                'allowWrite' => [
                    "{$commonDir}/objects", "{$commonDir}/refs", "{$commonDir}/logs", "{$commonDir}/packed-refs",
                    $gitDir,                         // the worktree's own index and HEAD
                    storage_path('foreman/cache'),   // see env()
                    // Cargo has no cache variable, and moving CARGO_HOME would move its bin folder and config too
                    "{$cargo}/registry", "{$cargo}/git",
                ],
                // Each of these can point git at another git folder, whose config could run code
                'denyWrite' => ["{$worktree}/.git", "{$gitDir}/commondir", "{$gitDir}/gitdir", "{$gitDir}/config.worktree"],
                'denyRead' => array_map(fn (string $dir) => "{$home}/{$dir}", self::SECRET_DIRS),
            ],
        ];
    }

    /**
     * Git settings for the agent. The sandbox blocks gpg-agent, so the agent commits unsigned and Foreman signs before it pushes.
     *
     * @return array<string,string>
     */
    public static function gitConfig(): array
    {
        return ['commit.gpgsign' => 'false'];
    }

    /**
     * Package caches. The sandbox blocks writes to your own, and Foreman's keep a script in one
     * repository from tampering with the caches your other projects use.
     *
     * @return array<string,string>
     */
    public static function env(): array
    {
        $cache = fn (string $tool) => storage_path("foreman/cache/{$tool}");

        return [
            'npm_config_cache' => $cache('npm'),
            'npm_config_store_dir' => $cache('pnpm'),
            'YARN_CACHE_FOLDER' => $cache('yarn'),
            'COMPOSER_CACHE_DIR' => $cache('composer'),
            'PIP_CACHE_DIR' => $cache('pip'),
            'UV_CACHE_DIR' => $cache('uv'),
            'POETRY_CACHE_DIR' => $cache('poetry'),
        ];
    }
}
