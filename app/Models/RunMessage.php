<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message in a run's thread: an agent's question, or what the operator wrote in Foreman. */
class RunMessage extends Model
{
    public const AGENT = 'agent';

    public const HUMAN = 'human';

    protected $guarded = ['id'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
