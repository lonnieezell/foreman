<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\GitHub;
use App\Services\Usage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ProcessRun implements ShouldQueue
{
    use Queueable;

    /** Where a review round writes its verdicts, relative to the worktree. */
    public const REVIEW_FILE = '.foreman/review.json';

    /** A Bash command matching this moves the run to Checking: tests, linters, formatters, type checks. */
    public const CHECK_COMMAND = '/\b(pest|phpunit|phpstan|pint|artisan test|vitest|jest|playwright|eslint|prettier|biome|tsc|rustfmt|pytest|ruff|mypy|pyright)\b'
        .'|\b(npm|pnpm|yarn|bun|composer)\s+(run\s+)?(test|lint|typecheck|type-check|check|format)\b'
        .'|\bcargo\s+(\+\S+\s+)?(test|nextest|clippy|fmt|check)\b/';

    public int $tries = 1;

    public int $timeout = 0;   // we enforce max_minutes ourselves so we can clean up

    private string $buffer = '';

    private ?array $result = null;

    private bool $limitReported = false;

    public function __construct(public Run $run) {}

    public function handle(GitHub $github): void
    {
        $run = $this->run->fresh();
        $repo = $run->repository;

        // Cancelled while it was waiting for a worker
        if ($run->status !== RunStatus::Queued) {
            return;
        }

        $run->moveTo(RunStatus::Preparing, ['started_at' => now(), 'heartbeat_at' => now()]);

        try {
            $worktree = $run->resume_session_id && $run->worktree_path && File::isDirectory($run->worktree_path)
                ? $run->worktree_path          // continuing: keep the agent's work in progress
                : $this->prepareWorktree($run);

            if ($run->pr_round) {
                $this->prepareReviewFile($worktree);
            }

            $this->runAgent($run, $worktree, $github);
            $this->settle($run, $github);
        } catch (Throwable $e) {
            $run->refresh();
            $cancelled = $run->cancel_requested;

            $run->moveTo($cancelled ? RunStatus::Cancelled : RunStatus::Failed, [
                'error' => $cancelled ? null : $e->getMessage(),
                'finished_at' => now(),
            ]);
            $run->log($cancelled ? 'Cancelled.' : 'Failed: '.$e->getMessage());

            if (! $cancelled) {
                $quoted = collect(explode("\n", $e->getMessage()))->map(fn ($line) => rtrim("> {$line}"))->implode("\n");
                $github->flagForHuman($repo, $run->issue_number,
                    "The Foreman run for this issue failed before finishing:\n\n{$quoted}\n\nThe worktree was kept at `{$run->worktree_path}` for inspection.");
            }
        } finally {
            $github->release($repo, $run->issue_number);
        }
    }

    private function prepareWorktree(Run $run): string
    {
        $repo = $run->repository;
        $worktree = $repo->worktreePath($run->issue_number);

        $this->step($run, 'Fetching origin', ['git', 'fetch', 'origin', '--quiet'], $repo->local_path);

        // A previous run for the same issue may have left a worktree behind
        if (File::isDirectory($worktree)) {
            $this->step($run, 'Removing old worktree', ['git', 'worktree', 'remove', '--force', $worktree], $repo->local_path);
        }

        // Continuing without a worktree: rebuild from what was pushed, never reset the branch to base
        $pushed = ($run->resume_session_id || $run->pr_round) && Process::path($repo->local_path)
            ->run(['git', 'rev-parse', '--verify', '--quiet', "origin/{$run->branch}"])->successful();
        $from = $pushed ? "origin/{$run->branch}" : "origin/{$repo->base_branch}";

        File::ensureDirectoryExists(dirname($worktree));
        $this->step($run, "Creating worktree on {$run->branch} from {$from}",
            ['git', 'worktree', 'add', '-B', $run->branch, $worktree, $from], $repo->local_path);

        $run->update(['worktree_path' => $worktree]);

        $this->step($run, 'Running setup script', ['bash', '-ec', $repo->setup_script], $worktree, timeout: 900);

        return $worktree;
    }

    private function step(Run $run, string $label, array $command, string $cwd, int $timeout = 300): void
    {
        $run->log($label);
        $this->guardCancel($run);

        $result = Process::path($cwd)->timeout($timeout)->run($command);
        $run->update(['heartbeat_at' => now()]);

        if ($result->failed()) {
            $output = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException("{$label} failed: ".str($output)->limit(500));
        }
    }

    private function runAgent(Run $run, string $worktree, GitHub $github): void
    {
        $repo = $run->repository;
        $run->moveTo(RunStatus::Working, ['claude_profile' => $repo->profileName()]);
        $effort = $run->effort ? "{$run->effort} effort, " : '';
        $run->log("Starting Claude ({$run->model}, {$effort}{$repo->profileName()} profile, max {$repo->max_turns} turns, {$repo->max_minutes} min)");

        $prompt = match (true) {
            (bool) $run->pr_round => $this->roundPrompt($run),
            (bool) $run->resume_session_id => $this->resumePrompt($run),
            default => $repo->renderPrompt($run, $github->sanitisedIssue($repo, $run->issue_number)),
        };

        $command = [
            'claude', '-p', $prompt,
            '--model', $run->model,
            '--max-turns', (string) $repo->max_turns,
            // Rounds read CI results; GitHub writes stay with Foreman
            '--allowedTools', $repo->allowed_tools.($run->pr_round ? ',Bash(gh pr checks:*),Bash(gh run view:*)' : ''),
            '--output-format', 'stream-json',
            '--verbose',
        ];

        if ($run->effort) {
            array_push($command, '--effort', $run->effort);
        }

        if ($run->resume_session_id) {
            // Same conversation, so the agent doesn't re-explore the codebase
            array_push($command, '--resume', $run->resume_session_id);
        }

        $process = Process::path($worktree)
            ->env($repo->claudeEnv())
            ->forever()
            ->start($command, fn (string $type, string $output) => $type === 'out' ? $this->consume($run, $output) : null);

        $run->update(['pid' => $process->id()]);
        $deadline = now()->addMinutes($repo->max_minutes);

        while ($process->running()) {
            sleep(2);
            $run->update(['heartbeat_at' => now()]);

            if ($run->fresh()->cancel_requested) {
                $process->signal(15); // SIGTERM
                $process->wait();
                throw new RuntimeException('Cancelled');
            }

            if (now()->greaterThan($deadline)) {
                $process->signal(15); // SIGTERM
                $process->wait();
                throw new RuntimeException("Hit the {$repo->max_minutes}-minute limit.");
            }
        }

        $result = $process->wait();
        $this->consume($run, "\n"); // flush any final partial line

        $run->update([
            'pid' => null,
            'cost_usd' => $this->result['total_cost_usd'] ?? 0,
            'num_turns' => $this->result['num_turns'] ?? null,
            'input_tokens' => $this->result['usage']['input_tokens'] ?? null,
            'output_tokens' => $this->result['usage']['output_tokens'] ?? null,
            'cache_read_tokens' => $this->result['usage']['cache_read_input_tokens'] ?? null,
            'cache_write_tokens' => $this->result['usage']['cache_creation_input_tokens'] ?? null,
        ]);

        $this->holdIfOutOfUsage($run);

        if ($this->result === null && $result->failed()) {
            throw new RuntimeException('Claude exited without a result: '.str($result->errorOutput())->limit(500));
        }
    }

    /** Turn Claude's stream-json into a readable log and stage updates. */
    private function consume(Run $run, string $chunk): void
    {
        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);

            if ($line === '') {
                continue;
            }

            File::append($run->rawLogPath(), $line."\n");
            $event = json_decode($line, true);

            if (! is_array($event)) {
                continue;
            }

            if (isset($event['session_id']) && $run->session_id !== $event['session_id']) {
                $run->update(['session_id' => $event['session_id']]);
            }

            if (($event['type'] ?? null) === 'rate_limit_event') {
                app(Usage::class)->record($run->repository->profileName(), $event['rate_limit_info'] ?? []);
                $this->limitReported = ($event['rate_limit_info']['status'] ?? null) === 'rejected';

                continue;
            }

            if (($event['type'] ?? null) === 'result') {
                $this->result = $event;
                $run->log(sprintf('Finished after %d turns, $%.2f', $event['num_turns'] ?? 0, $event['total_cost_usd'] ?? 0));

                continue;
            }

            if (($event['type'] ?? null) !== 'assistant') {
                continue;
            }

            foreach ($event['message']['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'text' && trim($block['text']) !== '') {
                    $run->log((string) str($block['text'])->squish()->limit(300));
                }

                if (($block['type'] ?? null) === 'tool_use') {
                    $this->logToolUse($run, $block);
                }
            }
        }
    }

    private function logToolUse(Run $run, array $block): void
    {
        $input = $block['input'] ?? [];
        $name = $block['name'] ?? 'tool';

        $summary = match ($name) {
            'Bash' => '$ '.($input['command'] ?? ''),
            'Edit', 'Write', 'Read' => "{$name} ".($input['file_path'] ?? ''),
            default => $name,
        };

        $run->log((string) str($summary)->limit(200));

        // Move the run along the line when the agent starts checking its work
        $command = $input['command'] ?? '';
        if ($name === 'Bash' && preg_match(self::CHECK_COMMAND, $command)) {
            if ($run->status === RunStatus::Working) {
                $run->moveTo(RunStatus::Checking);
            }
        }
    }

    /** Decide how the run ended by looking at GitHub, not at what the agent claims. */
    private function settle(Run $run, GitHub $github): void
    {
        if ($run->pr_round) {
            $this->settleRound($run, $github);

            return;
        }

        $repo = $run->repository;
        $prUrl = $github->pullRequestFor($repo, $run->branch);
        $labels = $github->labels($repo, $run->issue_number);

        if (in_array($repo->human_label, $labels, true)) {
            $run->moveTo(RunStatus::NeedsHuman, ['pr_url' => $prUrl, 'finished_at' => now()]);
            $run->log('Agent flagged the issue for a human.');

            return;
        }

        if ($prUrl && $repo->watch_prs) {
            $this->watch($run, $github->pullRequest($repo, $prUrl)['head_sha'], ['pr_url' => $prUrl]);
            $run->log("PR opened: {$prUrl}. Watching it for checks and review bots.");

            return;
        }

        if ($prUrl) {
            $run->moveTo(RunStatus::PrOpened, ['pr_url' => $prUrl, 'finished_at' => now()]);
            $run->log("PR opened: {$prUrl}");
            $run->removeWorktree();

            return;
        }

        $reason = ($this->result['is_error'] ?? false)
            ? 'Claude stopped with an error ('.($this->result['subtype'] ?? 'unknown').').'
            : 'The agent finished without opening a PR or flagging the issue.';

        // Its last message usually says why, so keep it where a human will see it
        $last = trim($this->result['result'] ?? '');

        throw new RuntimeException($last === '' ? $reason : "{$reason} Its last message:\n\n".str($last)->limit(2000));
    }

    /** Post the agent's verdicts on the bot threads it was given, then watch the new head. */
    private function settleRound(Run $run, GitHub $github): void
    {
        $repo = $run->repository;
        $head = $github->pullRequest($repo, $run->pr_url)['head_sha'];
        $pushed = $head !== $run->head_sha;
        $verdicts = $this->readVerdicts($run->worktree_path);

        foreach ($run->pr_feedback['threads'] ?? [] as $thread) {
            $verdict = $verdicts[$thread['id']] ?? null;
            $reply = trim($verdict['reply'] ?? '');

            [$body, $resolve] = match (true) {
                $verdict === null => [null, false],
                $verdict['verdict'] === 'fixed' && $pushed => ['Fixed in '.substr($head, 0, 7).": {$reply}", true],
                $verdict['verdict'] === 'declined' => ["Not changing: {$reply}", true],
                $verdict['verdict'] === 'unsure' => ["Leaving for a human: {$reply}", false],
                default => [null, false],   // "fixed" with nothing pushed, or an unknown verdict
            };

            if ($body) {
                $github->replyToThread($repo, $thread['id'], $body);
            }

            if ($resolve) {
                $github->resolveThread($repo, $thread['id']);
            }

            $run->log("Thread {$thread['url']}: ".($resolve ? 'resolved' : 'left open').($body ? '' : ', no reply posted'));
        }

        $this->watch($run, $head);
        $run->log($pushed ? 'Pushed '.substr($head, 0, 7).'. Watching the PR again.' : 'Nothing new pushed. Watching the PR again.');
    }

    private function watch(Run $run, string $head, array $extra = []): void
    {
        $run->moveTo(RunStatus::WatchingPr, [
            'head_sha' => $head,
            'watch_started_at' => now(),
            'finished_at' => now(),
            ...$extra,
        ]);
    }

    /** @return array<string,array{verdict:string,reply?:string}> thread id => verdict; anything unreadable is left out */
    private function readVerdicts(string $worktree): array
    {
        $entries = json_decode((string) @file_get_contents("{$worktree}/".self::REVIEW_FILE), true);

        return collect(is_array($entries) ? $entries : [])
            ->filter(fn ($e) => is_array($e) && is_string($e['thread_id'] ?? null) && is_string($e['verdict'] ?? null))
            ->keyBy('thread_id')
            ->all();
    }

    /** Clear the last round's verdicts and keep the file out of git without touching .gitignore. */
    private function prepareReviewFile(string $worktree): void
    {
        File::delete("{$worktree}/".self::REVIEW_FILE);

        $exclude = trim(Process::path($worktree)->run(['git', 'rev-parse', '--git-path', 'info/exclude'])->output());

        if ($exclude === '') {
            return;
        }

        $exclude = str_starts_with($exclude, '/') ? $exclude : "{$worktree}/{$exclude}";

        if (! str_contains((string) @file_get_contents($exclude), "\n.foreman/\n")) {
            File::ensureDirectoryExists(dirname($exclude));
            File::append($exclude, "\n.foreman/\n");
        }
    }

    private function roundPrompt(Run $run): string
    {
        $checks = collect($run->pr_feedback['checks'] ?? [])
            ->map(fn ($c) => "- {$c['name']}: {$c['state']}".($c['url'] ? " ({$c['url']})" : ''))
            ->implode("\n") ?: 'None.';

        $threads = collect($run->pr_feedback['threads'] ?? [])
            ->map(fn ($t) => "### {$t['id']}\n{$t['author']} on {$t['path']}".($t['line'] ? ":{$t['line']}" : '')."\n\n{$t['body']}")
            ->implode("\n\n") ?: 'None.';

        return strtr(file_get_contents(resource_path('prompts/pr-round.md')), [
            '{{issue}}' => (string) $run->issue_number,
            '{{branch}}' => $run->branch,
            '{{pr}}' => $run->pr_url,
            '{{round}}' => (string) $run->pr_round,
            '{{max_rounds}}' => (string) config('foreman.pr_watch.max_rounds'),
            '{{review_file}}' => self::REVIEW_FILE,
            '{{checks}}' => $checks,
            '{{threads}}' => $threads,
        ]);
    }

    private function resumePrompt(Run $run): string
    {
        return "A human has replied on issue #{$run->issue_number}. Read the new comments with "
            ."`gh issue view {$run->issue_number} --comments`, then continue from where you stopped, "
            .'following the same instructions as before.';
    }

    /**
     * Running out of usage holds the profile until it resets, instead of failing every queued issue in turn.
     * The rejected rate_limit_event normally does that; this covers Claude stopping without one.
     */
    private function holdIfOutOfUsage(Run $run): void
    {
        $text = ($this->result['result'] ?? '').' '.($this->result['subtype'] ?? '');

        if (($this->result['is_error'] ?? false) && preg_match('/usage limit|rate limit|quota/i', $text)) {
            $profile = $run->repository->profileName();

            if (! $this->limitReported) {
                app(Usage::class)->recordLimitHit($profile);
            }

            $run->log("Usage limit reached. Holding the {$profile} profile until it resets.");
        }
    }

    private function guardCancel(Run $run): void
    {
        if ($run->fresh()->cancel_requested) {
            throw new RuntimeException('Cancelled');
        }
    }
}
