<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * On a subscription, factory runs share each profile's /usage limits with interactive sessions.
 * Claude reports those limits in stream-json as rate_limit_event, so we keep the latest per
 * profile and hold new runs when the numbers say there isn't room for them.
 */
class Usage
{
    private const WEEK = 7 * 86400;

    /** Profiles whose "Run anyway" was used this poll, so every repository on them gets through. */
    private array $overridden = [];

    /** Save a rate_limit_info payload as the profile's latest snapshot. */
    public function record(string $profile, array $info): void
    {
        $window = fn (array $w) => [
            'utilization' => (float) ($w['utilization'] ?? 0),
            'resets_at' => $w['resetsAt'] ?? null,
            'status' => $w['status'] ?? 'allowed',
        ];

        Setting::put("usage:{$profile}", json_encode([
            'captured_at' => now()->toIso8601String(),
            'status' => $info['status'] ?? 'allowed',
            'resets_at' => $info['resetsAt'] ?? null,
            'windows' => array_map($window, $info['unifiedWindows'] ?? []),
        ]));
    }

    /** Claude said it hit a limit without reporting which; hold until a probe finds out. */
    public function recordLimitHit(string $profile): void
    {
        $this->record($profile, ['status' => 'rejected']);
    }

    public function snapshot(string $profile): ?array
    {
        $json = Setting::get("usage:{$profile}");

        return $json === null ? null : json_decode($json, true);
    }

    /** Why new runs on this profile should wait, or null if they can start. */
    public function holdReason(string $profile): ?string
    {
        $snapshot = $this->snapshot($profile);
        if ($snapshot === null) {
            return null;
        }

        $config = config('factory.usage');
        $five = $this->liveWindow($snapshot, 'five_hour');
        $week = $this->liveWindow($snapshot, 'seven_day');

        if ($five && ($five['status'] === 'rejected' || $five['utilization'] >= $config['five_hour_ceiling'])) {
            return sprintf('5-hour usage at %d%%, resets %s.', $this->percent($five['utilization']), $this->when($five['resets_at']));
        }

        if ($week && ($week['status'] === 'rejected' || $week['utilization'] >= $config['weekly_cap'])) {
            return sprintf('Weekly usage at %d%%, resets %s.', $this->percent($week['utilization']), $this->when($week['resets_at'], 'D g:ia'));
        }

        if ($week && $week['utilization'] > $this->weeklyPace($week)) {
            return sprintf('Weekly usage at %d%%, ahead of the %d%% pace for this point in the week.',
                $this->percent($week['utilization']), $this->percent($this->weeklyPace($week)));
        }

        if ($snapshot['status'] === 'rejected' && ! $this->hasReset($snapshot['resets_at'])) {
            return 'Claude reported a usage limit'.($snapshot['resets_at'] ? ', resets '.$this->when($snapshot['resets_at']).'.' : '.');
        }

        return null;
    }

    /** Whether the poller may start runs for this repository, probing first if the snapshot is stale. */
    public function allows(Repository $repo): bool
    {
        $profile = $repo->profileName();

        if (isset($this->overridden[$profile]) || Setting::get("usage_override:{$profile}") !== null) {
            Setting::whereKey("usage_override:{$profile}")->delete();
            $this->overridden[$profile] = true;

            return true;
        }

        $snapshot = $this->snapshot($profile);
        if ($snapshot === null || Carbon::parse($snapshot['captured_at'])->lt(now()->subMinutes(config('factory.usage.probe_minutes')))) {
            $this->probe($repo);
        }

        return $this->holdReason($profile) === null;
    }

    /** Ignore the hold on this profile for the next poll. */
    public function override(string $profile): void
    {
        Setting::put("usage_override:{$profile}", 1);
    }

    public function overridePending(string $profile): bool
    {
        return Setting::get("usage_override:{$profile}") !== null;
    }

    /** Ask Claude for fresh numbers with the cheapest call we can make. Never blocks the factory. */
    public function probe(Repository $repo): void
    {
        try {
            // Outside the project, so no CLAUDE.md or project settings get loaded
            $dir = sys_get_temp_dir().'/factory-usage-probe';
            File::ensureDirectoryExists($dir);

            $output = Process::path($dir)->env($repo->claudeEnv())->timeout(60)->run([
                'claude', '-p', 'ok', '--model', 'haiku', '--max-turns', '1', '--tools', '',
                '--strict-mcp-config', '--no-session-persistence', '--output-format', 'stream-json', '--verbose',
            ])->output();
        } catch (Throwable) {
            return;
        }

        foreach (explode("\n", $output) as $line) {
            $event = json_decode($line, true);

            if (($event['type'] ?? null) === 'rate_limit_event') {
                $this->record($repo->profileName(), $event['rate_limit_info'] ?? []);
            }
        }
    }

    /**
     * Numbers for a profile's dashboard gauge, or null with no snapshot yet. Each window's limit is
     * where it holds runs: the 5-hour ceiling, or the weekly pace for this point in the week.
     *
     * @return array{five_hour:?array{percent:int,limit:int,tone:string,note:string},seven_day:?array{percent:int,limit:int,tone:string,note:string},held:bool,as_of:string}|null
     */
    public function gauge(string $profile): ?array
    {
        $snapshot = $this->snapshot($profile);
        if ($snapshot === null) {
            return null;
        }

        $window = function (string $name, callable $limit, callable $note) use ($snapshot): ?array {
            $w = $snapshot['windows'][$name] ?? null;

            if ($w === null) {
                return null;
            }

            $limitFraction = $limit($w);

            if ($this->hasReset($w['resets_at'])) {
                return ['percent' => 0, 'limit' => $this->percent($limitFraction), 'tone' => 'good', 'note' => 'reset'];
            }

            return [
                'percent' => $this->percent($w['utilization']),
                'limit' => $this->percent($limitFraction),
                'tone' => match (true) {
                    $w['status'] === 'rejected' || $w['utilization'] >= $limitFraction => 'bad',
                    $w['utilization'] >= $limitFraction - 0.1 => 'attention',
                    default => 'good',
                },
                'note' => $note($w),
            ];
        };

        return [
            'five_hour' => $window('five_hour',
                fn () => config('factory.usage.five_hour_ceiling'),
                fn ($w) => 'resets '.$this->when($w['resets_at'])),
            'seven_day' => $window('seven_day',
                fn ($w) => $this->weeklyPace($w),
                fn ($w) => sprintf('pace %d%% · resets %s', $this->percent($this->weeklyPace($w)), $this->when($w['resets_at'], 'D'))),
            'held' => $this->holdReason($profile) !== null,
            'as_of' => Carbon::parse($snapshot['captured_at'])->diffForHumans(short: true),
        ];
    }

    /** How much of the week may be used by now: time elapsed plus the margin, never over the cap. */
    private function weeklyPace(array $week): float
    {
        $elapsed = $week['resets_at'] === null ? 1 : 1 - ($week['resets_at'] - now()->timestamp) / self::WEEK;
        $config = config('factory.usage');

        return min($config['weekly_cap'], max(0, $elapsed) + $config['weekly_pace_margin']);
    }

    private function liveWindow(array $snapshot, string $name): ?array
    {
        $window = $snapshot['windows'][$name] ?? null;

        return $window && ! $this->hasReset($window['resets_at']) ? $window : null;
    }

    private function hasReset(?int $resetsAt): bool
    {
        return $resetsAt !== null && $resetsAt <= now()->timestamp;
    }

    private function percent(float $fraction): int
    {
        return (int) round($fraction * 100);
    }

    private function when(?int $timestamp, string $format = 'g:ia'): string
    {
        return $timestamp === null ? 'at an unknown time' : Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format($format);
    }
}
