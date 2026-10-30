<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'factory_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

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

    /** Each Claude profile has its own usage limit, so running out pauses only that profile. */
    public static function pauseProfile(string $profile, string $reason): void
    {
        static::put("usage_pause:{$profile}", $reason);
    }

    public static function resumeProfile(string $profile): void
    {
        static::whereKey("usage_pause:{$profile}")->delete();
    }

    public static function profilePaused(string $profile): bool
    {
        return static::get("usage_pause:{$profile}") !== null;
    }

    /** @return array<string,string> profile => reason */
    public static function pausedProfiles(): array
    {
        return static::where('key', 'like', 'usage_pause:%')
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($reason, $key) => [substr($key, strlen('usage_pause:')) => $reason])
            ->all();
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
