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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class Run extends Model
{
    /** The `kind` of a run started by Fix conflicts. */
    public const CONFLICT_FIX = 'conflict_fix';

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
            'question_until' => 'datetime',
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

    /** The operator's messages the agent hasn't had yet: not sent into a live session (ADR 0009). */
    public function unsentReplies(): HasMany
    {
        return $this->messages()->where('role', RunMessage::HUMAN)->whereNull('sent_at');
    }

    /** @param  list<string>  $questions  The agent's questions, kept in the run's thread (ADR 0001). */
    public function keepQuestions(array $questions): void
    {
        $this->messages()->createMany(array_map(fn (string $q) => ['role' => RunMessage::AGENT, 'body' => $q], $questions));
    }

    /** The run's measured facts for the improve loop, once `foreman:improve` scored it. */
    public function scorecard(): HasOne
    {
        return $this->hasOne(Scorecard::class);
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
            $name = match (true) {
                $run->isConflictFix() => 'Conflict fix',
                (bool) $run->pr_round => "Round {$run->pr_round}",
                default => 'Run '.++$number,
            };
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
     * The operator can post in the thread while the agent is live, or once the run stops. A run with a PR is the human's from here.
     * Only the issue's newest run takes messages, because a resume reads them from the run it continues.
     */
    public function takesMessages(): bool
    {
        return $this->isLive() || (! $this->status->isActive() && ! $this->pr_url && $this->isNewestForIssue());
    }

    /** The agent's session is running, so a message goes straight into it (ADR 0009). */
    public function isLive(): bool
    {
        return $this->status->isActive() && $this->pid !== null;
    }

    /** The agent asked a question and its session is still open for the answer (ADR 0009). */
    public function waitsForAnswer(): bool
    {
        return $this->isLive() && $this->question_until?->isFuture();
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

    /** The agent's first question on one line, for a notification. */
    public function firstQuestion(): string
    {
        return (string) str($this->messages()->where('role', RunMessage::AGENT)->value('body'))->squish();
    }

    /** Whether the operator wrote anything in Foreman that the agent hasn't had yet. */
    public function hasOperatorReply(): bool
    {
        return $this->unsentReplies()->exists();
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

        return $this->queueNext([], "Continuing run {$this->id} after a human reply.");
    }

    /** Queue a run that resumes this one's session in its worktree, on `$attributes` too. Its previous run is this one. */
    private function queueNext(array $attributes, string $log): self
    {
        $next = $this->repository->runs()->create([
            'issue_number' => $this->issue_number,
            'issue_title' => $this->issue_title,
            'issue_url' => $this->issue_url,
            'branch' => $this->branch,
            'status' => RunStatus::Queued,
            ...$this->nextStep(),
            'worktree_path' => $this->worktree_path,
            'resume_session_id' => $this->session_id,
            'previous_run_id' => $this->id,   // its finish time marks which comments are new
            ...$attributes,
        ]);
        $next->log($log);

        ProcessRun::dispatch($next)->onQueue('runs');

        return $next;
    }

    /**
     * The step a follow-up run starts with. In Auto mode it is this run's, which the job clamps again or a new label
     * overrides. In Fixed mode it is the repository's.
     */
    public function nextStep(): array
    {
        return $this->repository->isAuto()
            ? $this->only(['model', 'effort', 'difficulty', 'difficulty_source'])
            : ['model' => $this->repository->model, 'effort' => $this->repository->effort];
    }

    /** The newest earlier run on the issue that has a pick, so the pick is made once per issue (ADR 0004). */
    public function earlierPick(): ?self
    {
        return static::query()
            ->where('repository_id', $this->repository_id)
            ->where('issue_number', $this->issue_number)
            ->where('id', '<', $this->id)
            ->whereNotNull('difficulty_source')
            ->latest('id')
            ->first();
    }

    /**
     * Adds what a Claude call spent to the run's usage columns. A null value leaves its column as it is.
     *
     * @param  array<string,int|float|null>  $usage  Keyed by the usage columns, as `AgentResult::usage()` gives it.
     */
    public function addUsage(array $usage): void
    {
        $this->update(collect($usage)->map(fn ($value, $column) => $value === null ? $this->{$column} : $value + $this->{$column})->all());
    }

    /** Model and effort, and the difficulty when Foreman picked one: "sonnet high · medium". */
    public function stepLabel(): string
    {
        return trim("{$this->model} {$this->effort}").($this->difficulty ? " · {$this->difficulty}" : '');
    }

    public function isConflictFix(): bool
    {
        return $this->kind === self::CONFLICT_FIX;
    }

    /** The watcher stopped this run on a merge conflict, and nothing has run for the issue since. */
    public function canFixConflicts(): bool
    {
        return $this->status === RunStatus::NeedsHuman && $this->stop_reason === 'merge_conflict' && $this->pr_url
            && $this->isNewestForIssue();
    }

    /**
     * Start a run that merges the base branch and has the agent resolve the conflicts. It starts at once: a person
     * chose to spend the tokens, so pause, budget, slot and usage holds don't apply (ADR 0003).
     * Gives the new run, or why none started.
     */
    public function fixConflicts(GitHub $github): self|string
    {
        // The lock makes the check and the new run one step, so two clicks can't both pass the check
        return Cache::lock("foreman:fix-conflicts-{$this->id}", 30)->get(fn () => $this->startFixingConflicts($github))
            ?: 'Fix conflicts is already starting for this run.';
    }

    private function startFixingConflicts(GitHub $github): self|string
    {
        if (! $this->canFixConflicts()) {
            return 'This run has no conflicts left to fix. They may be being fixed already.';
        }

        // The page may be old, so ask GitHub again before spending tokens
        $pr = $github->pullRequest($this->repository, $this->pr_url);

        $refusal = match (true) {
            $pr['state'] !== 'OPEN' => 'The PR is no longer open. Nothing to fix.',
            $pr['head_sha'] !== $this->head_sha => 'Someone pushed to the branch since. Leaving it to you.',
            $pr['mergeable'] === 'UNKNOWN' => 'GitHub is still checking, try again in a minute.',
            $pr['mergeable'] !== 'CONFLICTING' => 'The PR no longer has conflicts. Nothing to fix.',
            default => null,
        };

        if ($refusal !== null) {
            return $refusal;
        }

        $github->reclaim($this->repository, $this->issue_number);

        return $this->queueNext([
            'kind' => self::CONFLICT_FIX,
            'pr_url' => $this->pr_url,
            'pr_round' => $this->pr_round,   // a conflict fix isn't a fix-up round, so it doesn't use one up
            'head_sha' => $this->head_sha,
            'risk_tier' => $this->risk_tier,
        ], "Fixing the merge conflicts on {$this->pr_url} (run {$this->id}).");
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
            ? 'Question: '.str($this->firstQuestion())->limit(90, preserveWords: true)
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
            // Not worded like the run errors, since it isn't why the run stopped
            $this->log("macOS notification not sent: {$error}");
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
            $result = Process::path($this->worktree_path)->env(ForemanEnv::forWorktree($this->worktree_path))->timeout(120)->run(['docker', 'compose', 'down', '-v']);
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
