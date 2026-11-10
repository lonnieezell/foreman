<?php

namespace App\Support;

use Dotenv\Dotenv;

class ForemanEnv
{
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
