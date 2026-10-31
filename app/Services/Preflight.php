<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Checks that everything Foreman depends on is actually there,
 * so problems show up in the UI instead of as silently stuck runs.
 */
class Preflight
{
    /** @return list<array{problem:string,fix:string}> */
    public function problems(): array
    {
        $problems = [];

        // Tool checks are slow-ish, so cache them for a few minutes.
        $tools = Cache::remember('foreman.preflight.tools', 300, fn () => [
            'gh' => Process::run(['gh', 'auth', 'status'])->successful(),
            'claude' => Process::run(['claude', '--version'])->successful(),
            'git' => Process::run(['git', '--version'])->successful(),
        ]);

        if (! $tools['git']) {
            $problems[] = ['problem' => 'git isn’t on the PATH of the process running Foreman.', 'fix' => 'Start Foreman from a terminal where `git --version` works.'];
        }
        if (! $tools['gh']) {
            $problems[] = ['problem' => 'GitHub CLI isn’t installed or isn’t logged in.', 'fix' => 'Run `gh auth login`, then restart Foreman.'];
        }
        if (! $tools['claude']) {
            $problems[] = ['problem' => 'Claude Code isn’t on the PATH.', 'fix' => 'Start Foreman from a terminal where `claude --version` works.'];
        }

        $lastPoll = Setting::get('last_poll_heartbeat');
        if ($lastPoll === null || Carbon::parse($lastPoll)->lt(now()->subMinutes(3))) {
            $problems[] = ['problem' => 'The scheduler isn’t running, so no repositories are being checked.', 'fix' => 'Start everything with `composer run foreman`.'];
        }

        $stuckJobs = DB::table('jobs')
            ->where('queue', 'runs')
            ->whereNull('reserved_at')
            ->where('created_at', '<', now()->subMinutes(2)->timestamp)
            ->count();

        if ($stuckJobs > 0) {
            $problems[] = ['problem' => "{$stuckJobs} run(s) have been waiting over two minutes for a worker.", 'fix' => 'Either all workers are busy, or none are running. Start more with `php artisan queue:work --queue=runs --timeout=0`.'];
        }

        return $problems;
    }

    public static function forget(): void
    {
        Cache::forget('foreman.preflight.tools');
    }
}
