<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use App\Services\Usage;
use App\Support\IssueSanitiser;
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

    /** Where the agent says how it ended, relative to the worktree. Foreman acts on it. */
    public const RESULT_FILE = '.foreman/result.json';

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
            if ($run->resume_session_id && $run->worktree_path && File::isDirectory($run->worktree_path)) {
                $worktree = $run->worktree_path;   // continuing: keep the agent's work in progress
                $this->catchUpWithBase($run, $worktree, fetch: true);
            } else {
                $worktree = $this->prepareWorktree($run);
            }

            $this->prepareForemanFiles($worktree);

            $this->runAgent($run, $worktree, $github, in_array('dependencies', $github->labels($repo, $run->issue_number), true));
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

        // Before the setup script, so dependencies the base branch added get installed
        if ($pushed) {
            $this->catchUpWithBase($run, $worktree, fetch: false);
        }

        $this->step($run, 'Running setup script', ['bash', '-ec', $repo->setup_script], $worktree, timeout: 900);

        return $worktree;
    }

    /**
     * Merge the latest base branch into a continuing run's branch, so fixes that landed there reach it.
     * Any failure leaves the branch as it was and the run goes on: the gate still catches what the base would have fixed.
     */
    private function catchUpWithBase(Run $run, string $worktree, bool $fetch): void
    {
        $base = "origin/{$run->repository->base_branch}";

        if ($fetch && ($fetched = Process::path($worktree)->run(['git', 'fetch', 'origin', '--quiet']))->failed()) {
            $run->log("Couldn't fetch origin, so {$base} wasn't merged. Going on from the branch as it was: ".str(trim($fetched->errorOutput()))->limit(500));

            return;
        }

        // `git merge --abort` can't always restore uncommitted changes, so never risk them
        if (trim(Process::path($worktree)->run(['git', 'status', '--porcelain', '--untracked-files=no'])->output()) !== '') {
            $run->log("Not merging {$base}: the worktree has uncommitted changes. Going on from the branch as it was.");

            return;
        }

        $run->log("Merging {$base} into {$run->branch}");
        try {
            $merge = Process::path($worktree)->timeout(300)->run(['git', 'merge', '--no-edit', $base]);
            $output = $merge->failed() ? trim($merge->errorOutput()."\n".$merge->output()) : null;
        } catch (Throwable $e) {
            $output = $e->getMessage();
        }

        if ($output !== null) {
            Process::path($worktree)->run(['git', 'merge', '--abort']);
            $run->log("Couldn't merge {$base}, so the merge was aborted. Going on from the branch as it was: ".str($output)->limit(500));
        }
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

    private function runAgent(Run $run, string $worktree, GitHub $github, bool $allowLockFiles): void
    {
        $repo = $run->repository;
        $run->moveTo(RunStatus::Working, ['claude_profile' => $repo->profileName()]);
        $effort = $run->effort ? "{$run->effort} effort, " : '';
        $run->log("Starting Claude ({$run->model}, {$effort}{$repo->profileName()} profile, max {$repo->max_turns} turns, {$repo->max_minutes} min)");

        $prompt = match (true) {
            (bool) $run->pr_round => $this->roundPrompt($run, $github),
            (bool) $run->resume_session_id => $this->resumePrompt($run, $github),
            default => $repo->renderPrompt($run, $github->sanitisedIssue($repo, $run->issue_number)),
        };

        $command = [
            'claude', '-p', $prompt,
            '--model', $run->model,
            '--max-turns', (string) $repo->max_turns,
            '--allowedTools', $repo->allowed_tools,
            '--permission-mode', 'dontAsk',
            '--settings', $this->writeHookSettings($run, $worktree, $allowLockFiles),
            '--output-format', 'stream-json',
            '--verbose',
        ];

        if ($repo->max_budget_per_run !== null) {
            array_push($command, '--max-budget-usd', $repo->max_budget_per_run);
        }

        if ($run->effort) {
            array_push($command, '--effort', $run->effort);
        }

        if ($run->resume_session_id) {
            // Same conversation, so the agent doesn't re-explore the codebase
            array_push($command, '--resume', $run->resume_session_id);
        }

        $process = Process::path($worktree)
            ->env([...$repo->claudeEnv(), ...self::agentEnv()])
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

    /**
     * Write the run's hook settings outside the worktree, so the agent can't edit them.
     * `foreman:guard` and `foreman:format-staged` run before tool calls, and `foreman:stop` when the agent tries to stop. All read their rules from a file next to the settings.
     */
    private function writeHookSettings(Run $run, string $worktree, bool $allowLockFiles): string
    {
        $dir = storage_path('foreman/hooks');
        File::ensureDirectoryExists($dir);

        $rules = "{$dir}/run-{$run->id}-rules.json";
        File::put($rules, json_encode([
            'worktree' => $worktree,
            'log' => $run->logPath(),
            'locked_paths' => $run->repository->lockedPathList(),
            'allow_lock_files' => $allowLockFiles,
            'base' => "origin/{$run->repository->base_branch}",
            'verify_script' => trim((string) $run->repository->verify_script),   // empty: the hook uses the detected stacks' checks
            'stop_counter' => "{$dir}/run-{$run->id}-stops",
        ]));
        File::delete("{$dir}/run-{$run->id}-stops");   // each start of the agent, resumes included, gets its own 3 blocks

        $hook = fn (string $name) => implode(' ', array_map('escapeshellarg', [PHP_BINARY, base_path('artisan'), $name, $rules]));
        $settings = "{$dir}/run-{$run->id}-settings.json";
        File::put($settings, json_encode(['hooks' => [
            'PreToolUse' => [[
                'matcher' => 'Bash|Edit|Write|MultiEdit|NotebookEdit',
                'hooks' => [
                    ['type' => 'command', 'command' => $hook('foreman:guard')],
                    ['type' => 'command', 'command' => $hook('foreman:format-staged')],
                ],
            ]],
            // The checks can take a while, so the timeout (in seconds) is longer than Claude Code's default
            'Stop' => [['hooks' => [['type' => 'command', 'command' => $hook('foreman:stop'), 'timeout' => 1800]]]],
        ]]));

        return $settings;
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
        $result = $this->readResult($run->worktree_path);

        if ($result === null) {
            $reason = ($this->result['is_error'] ?? false)
                ? 'Claude stopped with an error ('.($this->result['subtype'] ?? 'unknown').').'
                : 'The agent finished without a readable result file ('.self::RESULT_FILE.').';

            // Its last message usually says why, so keep it where a human will see it
            $last = trim($this->result['result'] ?? '');

            throw new RuntimeException($last === '' ? $reason : "{$reason} Its last message:\n\n".str($last)->limit(2000));
        }

        if ($result['outcome'] !== 'pr') {
            $github->flagForHuman($repo, $run->issue_number, $result['comment']);
            $run->moveTo(RunStatus::NeedsHuman, ['finished_at' => now()]);
            $run->log($result['outcome'] === 'already_done'
                ? 'Agent says the issue is already done. Flagged it for a human.'
                : 'Agent asked for a human'.(($result['reason'] ?? '') !== '' ? " ({$result['reason']})" : '').'.');

            return;
        }

        $footer = $this->review($run, $github);

        if ($footer === null) {
            return;
        }

        $this->push($run);

        $body = str_starts_with($result['body'], "Closes #{$run->issue_number}") ? $result['body'] : "Closes #{$run->issue_number}\n\n{$result['body']}";
        $body .= "\n\n{$footer}";
        $prUrl = $github->openPullRequest($repo, $run->branch, $result['title'], $body);

        if ($repo->watch_prs) {
            $this->watch($run, $github->pullRequest($repo, $prUrl)['head_sha'], ['pr_url' => $prUrl]);
            $run->log("PR opened: {$prUrl}. Watching it for checks and review bots.");

            return;
        }

        $run->moveTo(RunStatus::PrOpened, ['pr_url' => $prUrl, 'finished_at' => now()]);
        $run->log("PR opened: {$prUrl}");
        $run->removeWorktree();
    }

    /**
     * Foreman's own gate, outside any hook: the integrity check, the verify script and the size limit.
     * Returns the footer for the PR body, or null after flagging the issue for a human with the output.
     */
    private function review(Run $run, GitHub $github): ?string
    {
        $repo = $run->repository;
        $verify = trim((string) $repo->verify_script) ?: Repository::defaultVerifyScript($run->worktree_path, "origin/{$repo->base_branch}");
        $limit = (int) config('foreman.max_pr_lines');

        $run->moveTo(RunStatus::Reviewing);
        $run->log('Reviewing: running the integrity check and the verify script before pushing.');

        $failures = array_filter([
            $this->gateCheck($run, 'Test-integrity check', [PHP_BINARY, resource_path('guardrails/test-integrity.php'), "--base=origin/{$repo->base_branch}"], 120),
            $verify === '' ? null : $this->gateCheck($run, 'Verify script', ['bash', '-ec', $verify], 1500),
        ]);

        $lines = $this->changedLines($run);

        if ($lines > $limit) {
            $failures[] = "Size limit: the change is {$lines} changed lines (added plus deleted), over the limit of {$limit}.";
        }

        if ($failures) {
            $quoted = collect(explode("\n", implode("\n\n", $failures)))->map(fn ($line) => rtrim("> {$line}"))->implode("\n");
            $github->flagForHuman($repo, $run->issue_number,
                "Foreman's gate stopped this run before pushing. Nothing was pushed, and the branch is still in the worktree.\n\n{$quoted}");
            $run->moveTo(RunStatus::NeedsHuman, ['finished_at' => now()]);
            $run->log("Foreman's gate failed. Flagged the issue for a human.");

            return null;
        }

        $commands = collect(explode("\n", $verify))->map(fn ($c) => trim($c))->filter()->map(fn ($c) => "`{$c}`")->implode(', ');

        return "---\n**Foreman's gate**, run by Foreman outside the agent before the push:\n\n"
            .'- Verify script: '.($commands === '' ? 'none configured' : "passed ({$commands})")."\n"
            ."- Test integrity: passed\n"
            ."- Size: {$lines} changed lines (limit {$limit})";
    }

    /** The failure text when the command fails, or null when it passes. */
    private function gateCheck(Run $run, string $label, array $command, int $timeout): ?string
    {
        $this->guardCancel($run);
        $beat = 0;

        try {
            $process = Process::path($run->worktree_path)->timeout($timeout)->run($command, function () use ($run, &$beat) {
                if (time() - $beat >= 30) {
                    $beat = time();
                    $run->update(['heartbeat_at' => now()]);
                }
            });
        } catch (Throwable $e) {
            return "{$label} failed: {$e->getMessage()}";
        }

        $run->update(['heartbeat_at' => now()]);

        if ($process->successful()) {
            return null;
        }

        // Failures are at the end of the output, so keep the tail
        $output = trim((string) preg_replace('/\e\[[\d;]*m/', '', $process->output().$process->errorOutput()));

        return "{$label} failed (exit {$process->exitCode()}):\n".(strlen($output) > 3000 ? '…'.substr($output, -3000) : $output);
    }

    /** Lines added plus deleted on the branch since it left the base branch. */
    private function changedLines(Run $run): int
    {
        $diff = Process::path($run->worktree_path)->run(['git', 'diff', '--numstat', "origin/{$run->repository->base_branch}...HEAD"]);

        return (int) collect(explode("\n", trim($diff->output())))
            ->sum(fn ($row) => array_sum(array_map('intval', array_slice(explode("\t", $row), 0, 2))));   // binary files show "-"
    }

    /** Foreman's own push, with the agent's restrictions left off. */
    private function push(Run $run): void
    {
        $this->step($run, "Pushing {$run->branch}", ['git', 'push', '--set-upstream', 'origin', $run->branch], $run->worktree_path);
    }

    /**
     * Environment that stops the agent pushing: origin's push URL goes nowhere, no credential helper
     * answers, and GitHub tokens are dropped. A false value removes the variable.
     *
     * @return array<string,string|false>
     */
    public static function agentEnv(): array
    {
        return [
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'remote.origin.pushurl',
            'GIT_CONFIG_VALUE_0' => '/nonexistent/foreman-agents-cannot-push',
            'GIT_CONFIG_KEY_1' => 'credential.helper',
            'GIT_CONFIG_VALUE_1' => '',
            'GIT_TERMINAL_PROMPT' => '0',
            'GH_TOKEN' => false,
            'GITHUB_TOKEN' => false,
            'GH_ENTERPRISE_TOKEN' => false,
            'GITHUB_ENTERPRISE_TOKEN' => false,
            'GIT_ASKPASS' => false,
            'SSH_ASKPASS' => false,
            'SSH_AUTH_SOCK' => false,
        ];
    }

    /**
     * What the agent wrote in its result file, or null when it is missing or not one of the three outcomes.
     *
     * @return array{outcome:string,title?:string,body?:string,comment?:string,reason?:string}|null
     */
    private function readResult(string $worktree): ?array
    {
        $result = json_decode((string) @file_get_contents("{$worktree}/".self::RESULT_FILE), true);
        $text = fn (string $key) => is_array($result) && is_string($result[$key] ?? null) && trim($result[$key]) !== '';

        return match (is_array($result) ? ($result['outcome'] ?? null) : null) {
            'pr' => $text('title') && $text('body') ? $result : null,
            'needs_human' => $text('comment') && $text('reason') ? $result : null,
            'already_done' => $text('comment') ? $result : null,
            default => null,
        };
    }

    /** Post the agent's verdicts on the bot threads it was given, then watch the new head. */
    private function settleRound(Run $run, GitHub $github): void
    {
        $repo = $run->repository;
        $this->push($run);
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

    /** Clear the last run's result and verdicts, and keep them out of git without touching .gitignore. */
    private function prepareForemanFiles(string $worktree): void
    {
        File::delete(["{$worktree}/".self::RESULT_FILE, "{$worktree}/".self::REVIEW_FILE]);

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

    private function roundPrompt(Run $run, GitHub $github): string
    {
        $notPassing = $run->pr_feedback['checks'] ?? [];

        $checks = collect($notPassing)
            ->map(fn ($c) => "- {$c['name']}: {$c['state']}".($c['url'] ? " ({$c['url']})" : ''))
            ->merge(collect($github->failedCheckLogs($run->repository, $notPassing))
                ->map(fn ($l) => "\nLog of the failed steps for ".implode(', ', $l['checks']).", the end of it:\n".IssueSanitiser::wrapText($l['log'], clean: false)))
            ->implode("\n") ?: 'None.';

        $threads = collect($run->pr_feedback['threads'] ?? [])
            ->map(fn ($t) => "### {$t['id']}\n{$t['author']} on {$t['path']}".($t['line'] ? ":{$t['line']}" : '')."\n\n".IssueSanitiser::wrapText($t['body']))
            ->implode("\n\n") ?: 'None.';

        return strtr(file_get_contents(resource_path('prompts/pr-round.md')), [
            '{{issue}}' => (string) $run->issue_number,
            '{{branch}}' => $run->branch,
            '{{pr}}' => $run->pr_url,
            '{{round}}' => (string) $run->pr_round,
            '{{max_rounds}}' => (string) config('foreman.pr_watch.max_rounds'),
            '{{review_file}}' => self::REVIEW_FILE,
            '{{untrusted_note}}' => IssueSanitiser::TEXT_NOTE,
            '{{checks}}' => $checks,
            '{{threads}}' => $threads,
        ]);
    }

    private function resumePrompt(Run $run, GitHub $github): string
    {
        $comments = $github->trustedComments($run->repository, $run->issue_number, $run->previousRun?->finished_at)
            ?: 'No new comments from people with write access.';

        return "A human has replied on issue #{$run->issue_number}. The new comments are below. "
            .IssueSanitiser::TEXT_NOTE
            ."\n\n{$comments}\n\nContinue from where you stopped, following the same instructions as before.";
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
