<?php

use Illuminate\Support\Facades\Schedule;

// Cheap: only calls `gh`. Each repository's own interval decides whether it is actually checked.
Schedule::command('foreman:poll')->everyMinute()->withoutOverlapping();

// Scores ended runs that have no scorecard yet. In the background, so a long pass never holds up the poll (ADR 0008).
Schedule::command('foreman:improve')->dailyAt('03:00')->withoutOverlapping()->runInBackground();
