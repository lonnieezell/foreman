<?php

namespace App\Support;

use Dotenv\Dotenv;

class ForemanEnv
{
    /**
     * Environment for processes in a worktree: every variable from Foreman's own .env is removed.
     * Laravel puts those into the real environment, so children would inherit Foreman's APP_KEY,
     * and its settings would beat the target repo's own .env.
     *
     * @return array<string,false>
     */
    public static function removed(): array
    {
        $vars = Dotenv::createArrayBacked(app()->environmentPath(), app()->environmentFile())->safeLoad();

        return array_fill_keys(array_keys($vars), false);
    }
}
