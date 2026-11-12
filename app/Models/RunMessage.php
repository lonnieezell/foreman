<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

/** A message in a run's thread: an agent's question, or what the operator wrote in Foreman. */
class RunMessage extends Model
{
    public const AGENT = 'agent';

    public const HUMAN = 'human';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** The body escaped for HTML, with `backtick` spans shown as code. */
    public function bodyHtml(): HtmlString
    {
        return new HtmlString(preg_replace('/`([^`\n]+)`/', '<code>$1</code>', e($this->body)));
    }
}
