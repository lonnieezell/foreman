<?php

use Illuminate\Support\Facades\Schedule;

// Cheap: only calls `gh`. Each repository's own interval decides whether it is actually checked.
Schedule::command('foreman:poll')->everyMinute()->withoutOverlapping();
