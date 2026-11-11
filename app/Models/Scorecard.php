<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run's record for the improve loop: its measured facts, from plain code (ADR 0008). */
class Scorecard extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tool_errors' => 'array',
            'repeated_reads' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
