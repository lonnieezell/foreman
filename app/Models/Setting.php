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
