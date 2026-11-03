<?php

namespace App\Services;

/** The scope judge's answer about a change. */
final class ScopeVerdict
{
    public function __construct(
        public readonly bool $inScope,
        public readonly bool $weakensTests,
        public readonly string $reason,
    ) {}

    /** A change passes when it stays within the issue and leaves the tests alone. */
    public function passes(): bool
    {
        return $this->inScope && ! $this->weakensTests;
    }

    /** The problems and the reason, for the log, the PR body and the correction prompt. */
    public function describe(): string
    {
        $problems = array_filter([
            $this->inScope ? null : 'out of scope',
            $this->weakensTests ? 'weakens tests' : null,
        ]);

        return ucfirst(implode(', ', $problems)).": {$this->reason}";
    }
}
