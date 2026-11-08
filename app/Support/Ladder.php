<?php

namespace App\Support;

use App\Enums\Difficulty;
use App\Models\Repository;
use App\Models\Setting;
use Illuminate\Support\Arr;

/** The global map from each difficulty to the model and effort it runs, and the fallback step (ADR 0004). */
final class Ladder
{
    /** @return array<string,string> Models a step may use, cheapest first. Haiku is not allowed. */
    public static function models(): array
    {
        return Arr::except(Repository::MODELS, 'haiku');
    }

    public const DEFAULT = [
        'trivial' => ['model' => 'sonnet', 'effort' => 'low'],
        'small' => ['model' => 'sonnet', 'effort' => 'medium'],
        'medium' => ['model' => 'sonnet', 'effort' => 'high'],
        'large' => ['model' => 'opus', 'effort' => 'medium'],
        'hard' => ['model' => 'opus', 'effort' => 'high'],
    ];

    public const DEFAULT_FALLBACK = ['model' => 'sonnet', 'effort' => 'high'];

    /** @return array<string,array{model:string,effort:string}> The saved ladder, by difficulty value. */
    public static function steps(): array
    {
        return json_decode((string) Setting::get('ladder'), true) ?: self::DEFAULT;
    }

    /** @return array{model:string,effort:string} */
    public static function step(Difficulty $difficulty): array
    {
        return self::steps()[$difficulty->value];
    }

    /** @return array{model:string,effort:string} The step used when the classifier gives no usable answer twice. */
    public static function fallback(): array
    {
        return json_decode((string) Setting::get('fallback_step'), true) ?: self::DEFAULT_FALLBACK;
    }

    /** Orders steps by model first, then effort. */
    public static function rank(array $step): int
    {
        return array_search($step['model'], array_keys(self::models()), true) * 10
            + array_search($step['effort'], array_keys(Repository::EFFORTS), true);
    }

    /** Why the ladder can't be saved: a step cheaper than the one below it. Null when it is in order. */
    public static function orderProblem(array $steps): ?string
    {
        $below = null;

        foreach (Difficulty::values() as $difficulty) {
            $step = $steps[$difficulty];

            if ($below !== null && self::rank($step) < self::rank($steps[$below])) {
                return "{$difficulty} ({$step['model']} {$step['effort']}) is cheaper than {$below} ({$steps[$below]['model']} {$steps[$below]['effort']}) below it.";
            }

            $below = $difficulty;
        }

        return null;
    }
}
