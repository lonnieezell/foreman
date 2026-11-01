<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Services\GitHub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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
            'watch_started_at' => 'datetime',
            'handled_at' => 'datetime',
            'pr_feedback' => 'array',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** The run this one continues, for review rounds. */
    public function previousRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'previous_run_id');
    }

    /**
     * Every run for this repository and issue, oldest first, as strip entries. Review rounds show
     * their round number; the other runs are numbered in order.
     *
     * @return list<array{run:Run,label:string}>
     */
    public function attempts(): array
    {
        $number = 0;
        $attempts = [];

        $runs = static::query()
            ->where('repository_id', $this->repository_id)
            ->where('issue_number', $this->issue_number)
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $name = $run->pr_round ? "Round {$run->pr_round}" : 'Run '.++$number;
            $attempts[] = ['run' => $run, 'label' => "{$name} · {$run->status->label()}"];
        }

        return $attempts;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', RunStatus::activeValues());
    }

    /** Only each issue's newest run: the highest id per repository and issue number. */
    public function scopeLatestPerIssue(Builder $query): void
    {
        $query->whereIn('id', static::query()->selectRaw('max(id)')->groupBy('repository_id', 'issue_number'));
    }

    /** Latest runs of issues that had a run finish today, except issues that are active again. */
    public function scopeDoneToday(Builder $query): void
    {
        $query->latestPerIssue()
            ->whereNotIn('status', RunStatus::activeValues())
            ->whereExists(fn ($q) => $q->from('runs as earlier')
                ->whereColumn('earlier.repository_id', 'runs.repository_id')
                ->whereColumn('earlier.issue_number', 'runs.issue_number')
                ->whereNotIn('earlier.status', RunStatus::activeValues())
                ->whereRaw('coalesce(earlier.finished_at, earlier.updated_at) >= ?', [now()->startOfDay()]));
    }

    /** Resume the same Claude session in the same worktree, after the human replied on the issue. */
    public function continueAfterReply(GitHub $github): self
    {
        $github->reclaim($this->repository, $this->issue_number);

        $next = $this->repository->runs()->create([
            'issue_number' => $this->issue_number,
            'issue_title' => $this->issue_title,
            'issue_url' => $this->issue_url,
            'branch' => $this->branch,
            'status' => RunStatus::Queued,
            'model' => $this->repository->model,
            'effort' => $this->repository->effort,
            'worktree_path' => $this->worktree_path,
            'resume_session_id' => $this->session_id,
        ]);
        $next->log("Continuing run {$this->id} after a human reply.");

        ProcessRun::dispatch($next)->onQueue('runs');

        return $next;
    }

    public function moveTo(RunStatus $status, array $extra = []): void
    {
        $this->update(['status' => $status, ...$extra]);
    }

    public function statusLabel(): string
    {
        if ($this->cancel_requested && $this->status->isActive()) {
            return 'Cancelling';
        }

        if (! $this->pr_round) {
            return $this->status->label();
        }

        $label = $this->status === RunStatus::PrOpened ? 'Fixes pushed' : $this->status->label();

        return "{$label} · round {$this->pr_round}/".config('foreman.pr_watch.max_rounds');
    }

    public function removeWorktree(): void
    {
        if (! $this->repository->cleanup_worktrees || ! $this->worktree_path) {
            return;
        }

        Process::path($this->repository->local_path)->run(['git', 'worktree', 'remove', '--force', $this->worktree_path]);
        $this->log('Worktree removed.');
    }

    /** Human-readable log the UI tails. */
    public function logPath(): string
    {
        return storage_path("foreman/logs/run-{$this->id}.log");
    }

    /** Raw stream-json output from Claude, kept for debugging. */
    public function rawLogPath(): string
    {
        return storage_path("foreman/logs/run-{$this->id}.jsonl");
    }

    public function log(string $line): void
    {
        self::appendLog($this->logPath(), $line);
    }

    public static function appendLog(string $path, string $line): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::append($path, '['.now()->format('H:i:s').'] '.rtrim($line)."\n");
    }

    public function tail(int $lines = 400): string
    {
        if (! File::exists($this->logPath())) {
            return '';
        }

        $all = file($this->logPath(), FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * The tail grouped into entries for display. Lines without a timestamp, like a heredoc's body,
     * belong to the entry above them.
     *
     * @return list<array{time:string,kind:string,text:string,more:string}>
     */
    public function logEntries(): array
    {
        $entries = [];

        foreach (explode("\n", $this->tail()) as $line) {
            if (preg_match('/^\[(\d\d:\d\d:\d\d)\] (.*)$/', $line, $m)) {
                $entries[] = ['time' => $m[1], 'text' => $m[2], 'more' => []];
            } elseif ($entries) {
                $entries[array_key_last($entries)]['more'][] = $line;
            } elseif ($line !== '') {
                $entries[] = ['time' => '', 'text' => $line, 'more' => []];   // the tail began mid-entry
            }
        }

        return array_map(function (array $entry) {
            [$kind, $text] = $this->classifyLogLine($entry['text']);

            return ['time' => $entry['time'], 'kind' => $kind, 'text' => $text, 'more' => rtrim(implode("\n", $entry['more']))];
        }, $entries);
    }

    /** @return array{string,string} kind and the text to show */
    private function classifyLogLine(string $text): array
    {
        return match (true) {
            str_starts_with($text, '$ ') => ['command', substr($text, 2)],
            (bool) preg_match('/^(Read|Edit|Write) \//', $text) => ['tool', $this->worktree_path ? str_replace(rtrim($this->worktree_path, '/').'/', '', $text) : $text],
            (bool) preg_match('/^\w+$/', $text) => ['tool', $text],   // tools logged by name only, like Grep
            (bool) preg_match('/^(Failed|Marked as failed|Couldn’t|Cancelled|Usage limit)/u', $text) => ['error', $text],
            (bool) preg_match('/^(PR opened|Pushed|Finished after)/', $text) => ['good', $text],
            default => ['message', $text],
        };
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
