<?php

namespace App\Services;

/** The scope judge's answer about a change. */
final class ScopeVerdict
{
    public function __construct(
        public readonly bool $addsUnrequested,
        public readonly bool $missesRequested,
        public readonly bool $weakensTests,
        public readonly string $reason,
    ) {}

    /** A change passes when it does what the issue asks, nothing more, and leaves the tests alone. */
    public function passes(): bool
    {
        return ! $this->addsUnrequested && ! $this->missesRequested && ! $this->weakensTests;
    }

    /** Whether the only problem is work the issue asked for and the change left out. */
    public function onlyMissesWork(): bool
    {
        return $this->missesRequested && ! $this->addsUnrequested && ! $this->weakensTests;
    }

    /** The problems and the reason, for the log, the PR body and the correction prompt. */
    public function describe(): string
    {
        $problems = array_filter([
            $this->addsUnrequested ? 'adds unrequested work' : null,
            $this->missesRequested ? 'misses requested work' : null,
            $this->weakensTests ? 'weakens tests' : null,
        ]);

        return ucfirst(implode(', ', $problems)).": {$this->reason}";
    }
}
