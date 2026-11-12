<?php

namespace App\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

class ForemanEnv
{
    /** The worktree's own temp folder, relative to the worktree. */
    private const TMP_DIR = '.foreman/tmp';

    /**
     * removed(), plus TMPDIR pointed at the worktree's own temp folder, which this creates.
     * Tools such as PHPStan cache in the system temp folder, so a cache left by another checkout
     * or a concurrent run could break this one. The repository's `$toolchain` folders go in front of PATH.
     *
     * @return array<string,string|false>
     */
    public static function forWorktree(string $worktree, ?string $toolchain = null): array
    {
        $tmp = "{$worktree}/".self::TMP_DIR;

        // The folder ignores itself, so git never sees it, even before Foreman excludes .foreman/
        if (! File::exists("{$tmp}/.gitignore")) {
            File::ensureDirectoryExists($tmp);
            File::put("{$tmp}/.gitignore", "*\n");
        }

        return [...self::removed(), 'TMPDIR' => $tmp, ...self::path($toolchain)];
    }

    /**
     * PATH with the toolchain folders in front, or nothing when there are none, so PATH is inherited as it is.
     *
     * @return array<string,string>
     */
    private static function path(?string $toolchain): array
    {
        $toolchain = trim((string) $toolchain);
        $path = (string) getenv('PATH');

        // The hooks inherit the agent's PATH, which already starts with the toolchain
        if ($toolchain === '' || str_starts_with("{$path}:", "{$toolchain}:")) {
            return [];
        }

        return ['PATH' => $path === '' ? $toolchain : "{$toolchain}:{$path}"];
    }

    /**
     * Environment for processes in a worktree: every variable from Foreman's own .env is removed.
     * Laravel puts those into the real environment, so children would inherit Foreman's APP_KEY,
     * and its settings would beat the target repo's own .env. The variables Composer sets for
     * the workers it starts are removed too.
     *
     * @return array<string,false>
     */
    public static function removed(): array
    {
        $vars = Dotenv::createArrayBacked(app()->environmentPath(), app()->environmentFile())->safeLoad();

        // Set by Composer for the processes it starts (`composer foreman`). PHP_BINARY makes the
        // target repo's `@php` scripts run on Foreman's PHP instead of the PHP on its own PATH.
        $inherited = ['PHP_BINARY', 'COMPOSER_BINARY', 'COMPOSER_DEV_MODE'];

        return array_fill_keys([...array_keys($vars), ...$inherited], false);
    }
}
