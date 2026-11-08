<?php

namespace App\Enums;

use Closure;

/** How hard an issue is, easiest first. Each one runs the ladder's step for it (ADR 0004). */
enum Difficulty: string
{
    case Trivial = 'trivial';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Hard = 'hard';

    /** The issue label that sets this difficulty and skips the classifier. */
    public function label(): string
    {
        return "foreman:{$this->value}";
    }

    /** 0 for the easiest. */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** The hardest difficulty a `foreman:<difficulty>` label on the issue names, or null when none does. */
    public static function fromLabels(array $labels): ?self
    {
        return collect(self::cases())->last(fn (self $d) => in_array($d->label(), $labels, true));
    }

    /** A validation rule for a ceiling, which can't be below `$floor`. */
    public static function ceilingRule(string $floor): Closure
    {
        return function ($attribute, $value, $fail) use ($floor) {
            if (self::tryFrom($floor)?->rank() > self::tryFrom((string) $value)?->rank()) {
                $fail('The ceiling can’t be below the floor.');
            }
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
