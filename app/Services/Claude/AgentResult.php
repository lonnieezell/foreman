<?php

namespace App\Services\Claude;

/** What one agent pass left behind. */
final class AgentResult
{
    /**
     * @param  array|null  $outcome  The `result` event Claude ended on, or null when it never sent one.
     */
    public function __construct(
        public readonly ?array $outcome = null,
        public readonly ?string $sessionId = null,
    ) {}

    /**
     * What this pass spent, as Claude reported it, keyed by the Run's usage columns.
     *
     * @return array{cost_usd:float|int,num_turns:?int,input_tokens:?int,output_tokens:?int,cache_read_tokens:?int,cache_write_tokens:?int}
     */
    public function usage(): array
    {
        return [
            'cost_usd' => $this->outcome['total_cost_usd'] ?? 0,
            'num_turns' => $this->outcome['num_turns'] ?? null,
            'input_tokens' => $this->outcome['usage']['input_tokens'] ?? null,
            'output_tokens' => $this->outcome['usage']['output_tokens'] ?? null,
            'cache_read_tokens' => $this->outcome['usage']['cache_read_input_tokens'] ?? null,
            'cache_write_tokens' => $this->outcome['usage']['cache_creation_input_tokens'] ?? null,
        ];
    }

    /** Whether Claude ended on an error. */
    public function isError(): bool
    {
        return (bool) ($this->outcome['is_error'] ?? false);
    }

    /** Whether Claude ended on an error, or on nothing at all. */
    public function failed(): bool
    {
        return $this->outcome === null || $this->isError();
    }

    public function subtype(): ?string
    {
        return $this->outcome['subtype'] ?? null;
    }

    /** The agent's last message. */
    public function message(): string
    {
        return trim($this->outcome['result'] ?? '');
    }

    /** What the pass cost, in USD. */
    public function cost(): float
    {
        return (float) ($this->outcome['total_cost_usd'] ?? 0);
    }
}
