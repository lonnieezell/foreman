<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\File;

class Run extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'cost_usd' => 'decimal:4',
            'cancel_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', RunStatus::activeValues());
    }

    public function moveTo(RunStatus $status, array $extra = []): void
    {
        $this->update(['status' => $status, ...$extra]);
    }

    /** Human-readable log the UI tails. */
    public function logPath(): string
    {
        return storage_path("factory/logs/run-{$this->id}.log");
    }

    /** Raw stream-json output from Claude, kept for debugging. */
    public function rawLogPath(): string
    {
        return storage_path("factory/logs/run-{$this->id}.jsonl");
    }

    public function log(string $line): void
    {
        File::ensureDirectoryExists(dirname($this->logPath()));
        File::append($this->logPath(), '['.now()->format('H:i:s').'] '.rtrim($line)."\n");
    }

    public function tail(int $lines = 400): string
    {
        if (! File::exists($this->logPath())) {
            return '';
        }

        $all = file($this->logPath(), FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    /** New, cache-read and cache-write input together, since agent runs read mostly from cache. */
    public function inputTokens(): ?int
    {
        return $this->input_tokens === null
            ? null
            : $this->input_tokens + (int) $this->cache_read_tokens + (int) $this->cache_write_tokens;
    }

    /** @return array<string,array{in:int,out:int}> profile => today's tokens */
    public static function tokensTodayByProfile(): array
    {
        return static::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->whereNotNull('claude_profile')
            ->whereNotNull('input_tokens')
            ->groupBy('claude_profile')
            ->orderBy('claude_profile')
            ->selectRaw('claude_profile, sum(input_tokens + coalesce(cache_read_tokens, 0) + coalesce(cache_write_tokens, 0)) as tokens_in, sum(output_tokens) as tokens_out')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->claude_profile => ['in' => (int) $row->tokens_in, 'out' => (int) $row->tokens_out]])
            ->all();
    }

    public function duration(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        $seconds = (int) abs($this->started_at->diffInSeconds($this->finished_at ?? now()));

        return $seconds < 60 ? "{$seconds}s" : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }
}
