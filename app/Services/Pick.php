<?php

namespace App\Services;

use App\Enums\Difficulty;

/** The step a run in Auto mode gets, where it came from, and why (ADR 0004). */
final class Pick
{
    /**
     * @param  Difficulty|null  $difficulty  Null for a fallback step that stayed inside the repository's range.
     * @param  'classifier'|'label'|'fallback'  $source
     * @param  string|null  $clamped  What the floor or ceiling changed, or null when the pick was in range.
     */
    public function __construct(
        public readonly ?Difficulty $difficulty,
        public readonly string $source,
        public readonly string $reason,
        public readonly string $model,
        public readonly string $effort,
        public readonly ?string $clamped = null,
    ) {}

    /** The run's columns for this pick. */
    public function attributes(): array
    {
        return [
            'difficulty' => $this->difficulty?->value,
            'difficulty_source' => $this->source,
            'model' => $this->model,
            'effort' => $this->effort,
        ];
    }

    /** For the run log and the dry run. */
    public function describe(): string
    {
        return 'Difficulty: '.($this->difficulty?->value ?? 'none')." ({$this->source}) → {$this->model} {$this->effort}. {$this->reason}"
            .($this->clamped === null ? '' : " {$this->clamped}");
    }
}
