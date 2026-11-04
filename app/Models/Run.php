<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Services\GitHub;
use App\Services\Worktree;
use App\Support\ForemanEnv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
            'risk_tier' => 'integer',
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

    /** The run's thread: the agent's questions and what the operator wrote, in order. */
    public function messages(): HasMany
    {
        return $this->hasMany(RunMessage::class)->orderBy('id');
    }

    /** @return Collection<int,Run> This run and the runs it continues, oldest first. */
    public function chain(): Collection
    {
        $runs = collect([$this]);

        while ($previous = $runs->first()->previousRun) {
            $runs->prepend($previous);
        }

        return $runs;
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

    /** The issues on the dashboard's cards: latest runs, not dismissed, not in an archived repository. */
    public function scopeOnDashboard(Builder $query): void
    {
        $query->latestPerIssue()
            ->whereNull('handled_at')
            ->whereHas('repository', fn ($q) => $q->whereNull('archived_at'));
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

    /**
     * Continuing needs a session and a worktree to resume, and a run with a PR is the human's from here.
     * Only the issue's newest run continues, so a run is continued once and never beside a Start over.
     */
    public function canContinueAfterReply(): bool
    {
        return $this->status === RunStatus::NeedsHuman && $this->session_id && $this->worktree_path && ! $this->pr_url
            && $this->isNewestForIssue();
    }

    /**
     * The operator can post in the thread once the run stops. A run with a PR is the human's from here.
     * Only the issue's newest run takes messages, because a resume reads them from the run it continues.
     */
    public function takesMessages(): bool
    {
        return ! $this->status->isActive() && ! $this->pr_url && $this->isNewestForIssue();
    }

    private function isNewestForIssue(): bool
    {
        return static::latestPerIssue()->whereKey($this->id)->exists();
    }

    /** Stopped on the agent's `question` outcome, waiting for the operator's answer in Foreman. */
    public function askedQuestion(): bool
    {
        return $this->status === RunStatus::NeedsHuman && $this->stop_reason === 'question';
    }

    /** Whether the operator wrote anything in Foreman since the run stopped. */
    public function hasOperatorReply(): bool
    {
        return $this->messages()->where('role', RunMessage::HUMAN)->exists();
    }

    /**
     * Resume the same Claude session in the same worktree, after the human replied on the issue or in Foreman.
     * Null when the run can't be continued, such as when another click or tab already continued it.
     */
    public function continueAfterReply(GitHub $github): ?self
    {
        // The lock makes the check and the new run one step, so two clicks can't both pass the check
        return Cache::lock("foreman:continue-run-{$this->id}", 30)->get(fn () => $this->startContinuing($github)) ?: null;
    }

    private function startContinuing(GitHub $github): ?self
    {
        if (! $this->canContinueAfterReply()) {
            return null;
        }

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
            'previous_run_id' => $this->id,   // its finish time marks which comments are new
        ]);
        $next->log("Continuing run {$this->id} after a human reply.");

        ProcessRun::dispatch($next)->onQueue('runs');

        return $next;
    }

    public function moveTo(RunStatus $status, array $extra = []): void
    {
        $this->update(['status' => $status, ...$extra]);

        if ($status === RunStatus::NeedsHuman && $this->wasChanged('status') && Setting::nativeNotifications()) {
            $this->notifyNeedsYou();
        }
    }

    private function notifyNeedsYou(): void
    {
        $title = "Foreman: {$this->repository->name} #{$this->issue_number}";
        $body = $this->askedQuestion()
            ? 'Question: '.str($this->messages()->where('role', RunMessage::AGENT)->value('body'))->limit(90, preserveWords: true)
            : 'Needs you'.($this->stop_reason ? ': '.str_replace('_', ' ', $this->stop_reason) : '');

        // The text goes in as arguments after `--`, so it is never read as AppleScript or as an osascript option
        $script = ['-e', 'on run argv', '-e', 'display notification (item 2 of argv) with title (item 1 of argv)', '-e', 'end run'];

        // A notification is a courtesy: whatever goes wrong is logged and the run carries on
        try {
            $result = Process::timeout(5)->run(['osascript', ...$script, '--', $title, $body]);
            $error = $result->successful() ? null : trim($result->errorOutput() ?: $result->output());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            $this->log("Couldn’t send the macOS notification: {$error}");
        }
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

        $this->stopDockerServices();

        $this->worktree()->remove();
        $this->log('Worktree removed.');
    }

    /** This run's worktree, at `$path` when it isn't where the run says it is yet. */
    public function worktree(?string $path = null): Worktree
    {
        return app(Worktree::class, [
            'path' => $path ?? $this->worktree_path,
            'branch' => $this->branch,
            'base' => $this->repository->base_branch,
            'source' => $this->repository->local_path,
        ]);
    }

    /** Stops the compose services the setup script started, when the repository opted in and the worktree has a compose file. */
    private function stopDockerServices(): void
    {
        if (! $this->repository->stop_docker) {
            return;
        }

        $hasComposeFile = collect(['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'])
            ->contains(fn (string $file) => File::exists("{$this->worktree_path}/{$file}"));

        if (! $hasComposeFile) {
            return;
        }

        try {
            $result = Process::path($this->worktree_path)->env(ForemanEnv::removed())->timeout(120)->run(['docker', 'compose', 'down', '-v']);
        } catch (ProcessTimedOutException) {
            $this->log('docker compose down timed out.');

            return;
        }

        $this->log($result->successful() ? 'Docker services stopped.' : 'docker compose down failed: '.trim($result->errorOutput() ?: $result->output()));
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
