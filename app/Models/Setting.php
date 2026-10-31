<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Setting extends Model
{
    protected $table = 'foreman_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    // Keys under config('foreman') the settings page can override
    public const CONFIG_KEYS = [
        'usage.five_hour_ceiling', 'usage.weekly_pace_margin', 'usage.weekly_cap', 'usage.probe_minutes',
        'pr_watch.max_rounds', 'pr_watch.quiet_minutes', 'pr_watch.timeout_minutes',
    ];

    /**
     * Copies saved values over config('foreman'), restoring the env default for any that were cleared.
     * Runs at boot and before each queued job, since workers outlive a save.
     */
    public static function applyToConfig(): void
    {
        $defaults = config('foreman.defaults') ?? Arr::only(Arr::dot(config('foreman')), self::CONFIG_KEYS);
        config(['foreman.defaults' => $defaults]);

        $saved = static::whereIn('key', self::CONFIG_KEYS)->pluck('value', 'key');

        foreach ($defaults as $key => $default) {
            config(["foreman.{$key}" => isset($saved[$key]) ? $saved[$key] + 0 : $default]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function paused(): bool
    {
        return (bool) static::get('paused', false);
    }

    public static function desktopNotifications(): bool
    {
        return (bool) static::get('desktop_notifications', false);
    }

    public static function globalBudget(): ?float
    {
        $value = static::get('global_daily_budget');

        return $value === null || $value === '' ? null : (float) $value;
    }

    public static function spentToday(): float
    {
        return (float) Run::where('created_at', '>=', now()->startOfDay())->sum('cost_usd');
    }

    public static function globalBudgetReached(): bool
    {
        $budget = static::globalBudget();

        return $budget !== null && static::spentToday() >= $budget;
    }
}
