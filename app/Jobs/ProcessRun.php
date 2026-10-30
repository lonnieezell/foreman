<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ProcessRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 0;   // we enforce max_minutes ourselves so we can clean up

    private string $buffer = '';
    private ?array $result = null;

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
            $this->runAgent($run, $worktree);
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
                $github->flagForHuman($repo, $run->issue_number,
                    "The factory run for this issue failed before finishing:\n\n> {$e->getMessage()}\n\nThe worktree was kept at `{$run->worktree_path}` for inspection.");
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

        File::ensureDirectoryExists(dirname($worktree));
        $this->step($run, "Creating worktree on {$run->branch}",
            ['git', 'worktree', 'add', '-B', $run->branch, $worktree, "origin/{$repo->base_branch}"], $repo->local_path);

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

    private function runAgent(Run $run, string $worktree): void
    {
        $repo = $run->repository;
        $run->moveTo(RunStatus::Working);
        $run->log("Starting Claude ({$repo->model}, {$repo->profileName()} profile, max {$repo->max_turns} turns, {$repo->max_minutes} min)");

        $command = [
            'claude', '-p', $run->resume_session_id ? $this->resumePrompt($run) : $repo->renderPrompt($run),
            '--model', $repo->model,
            '--max-turns', (string) $repo->max_turns,
            '--allowedTools', $repo->allowed_tools,
            '--output-format', 'stream-json',
            '--verbose',
        ];

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

        $this->pauseIfOutOfUsage($run);

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
        if ($name === 'Bash' && preg_match('/\b(pest|phpunit|phpstan|pint|artisan test)\b/', $command)) {
            if ($run->status === RunStatus::Working) {
                $run->moveTo(RunStatus::Checking);
            }
        }
    }

    /** Decide how the run ended by looking at GitHub, not at what the agent claims. */
    private function settle(Run $run, GitHub $github): void
    {
        $repo = $run->repository;
        $prUrl = $github->pullRequestFor($repo, $run->branch);
        $labels = $github->labels($repo, $run->issue_number);

        if (in_array($repo->human_label, $labels, true)) {
            $run->moveTo(RunStatus::NeedsHuman, ['pr_url' => $prUrl, 'finished_at' => now()]);
            $run->log('Agent flagged the issue for a human.');

            return;
        }

        if ($prUrl) {
            $run->moveTo(RunStatus::PrOpened, ['pr_url' => $prUrl, 'finished_at' => now()]);
            $run->log("PR opened: {$prUrl}");

            if ($repo->cleanup_worktrees) {
                Process::path($repo->local_path)->run(['git', 'worktree', 'remove', '--force', $run->worktree_path]);
                $run->log('Worktree removed.');
            }

            return;
        }

        $reason = ($this->result['is_error'] ?? false)
            ? 'Claude stopped with an error ('.($this->result['subtype'] ?? 'unknown').').'
            : 'The agent finished without opening a PR or flagging the issue.';

        throw new RuntimeException($reason);
    }

    private function resumePrompt(Run $run): string
    {
        return "A human has replied on issue #{$run->issue_number}. Read the new comments with "
            ."`gh issue view {$run->issue_number} --comments`, then continue from where you stopped, "
            .'following the same instructions as before.';
    }

    /**
     * On a subscription, factory runs share your usage limit with interactive sessions.
     * When it runs out, pause that profile instead of failing every queued issue in turn.
     */
    private function pauseIfOutOfUsage(Run $run): void
    {
        $text = ($this->result['result'] ?? '').' '.($this->result['subtype'] ?? '');

        if (($this->result['is_error'] ?? false) && preg_match('/usage limit|rate limit|quota/i', $text)) {
            $profile = $run->repository->profileName();
            Setting::pauseProfile($profile, 'Claude reported a usage limit during run '.$run->id.'. Resume once your limit resets.');
            $run->log("Usage limit reached. Paused the {$profile} profile.");
        }
    }

    private function guardCancel(Run $run): void
    {
        if ($run->fresh()->cancel_requested) {
            throw new RuntimeException('Cancelled');
        }
    }
}
