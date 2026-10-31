<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Under `artisan serve`, Symfony Process only passes env vars that also
        // appear in $_SERVER, which drops HOME, so gh can't find its login.
        if (PHP_SAPI === 'cli-server') {
            $_SERVER += getenv();
        }

        try {
            Setting::applyToConfig();
        } catch (QueryException) {
            // The settings table doesn't exist until migrations have run
        }

        Queue::before(fn () => Setting::applyToConfig());
    }
}
