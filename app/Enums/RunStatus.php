<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';         // claimed, waiting for a free worker
    case Preparing = 'preparing';   // worktree + setup script
    case Working = 'working';       // agent is writing code
    case Checking = 'checking';     // agent is running tests / static analysis
    case PrOpened = 'pr_opened';
    case NeedsHuman = 'needs_human';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> The stations on a repository's line, in order. */
    public static function stations(): array
    {
        return [self::Queued, self::Preparing, self::Working, self::Checking];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::stations());
    }

    public function isActive(): bool
    {
        return in_array($this, self::stations(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Preparing => 'Setting up',
            self::Working => 'Writing code',
            self::Checking => 'Running checks',
            self::PrOpened => 'PR opened',
            self::NeedsHuman => 'Needs you',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Colour tone used by the UI. */
    public function tone(): string
    {
        return match ($this) {
            self::PrOpened => 'good',
            self::NeedsHuman => 'attention',
            self::Failed => 'bad',
            self::Cancelled => 'quiet',
            default => 'active',
        };
    }
}
