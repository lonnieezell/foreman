<?php

namespace App\Services\Claude;

use App\Enums\RunStatus;
use App\Guardrails\Sandbox;
use App\Models\Repository;
use App\Models\Run;
use App\Services\Usage;
use App\Support\ForemanEnv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** The real `claude` CLI. The only place that builds its command line. */
class ClaudeCli implements Claude
{
    /** A Bash command matching this moves the run to Checking: tests, linters, formatters, type checks. */
    public const CHECK_COMMAND = '/\b(pest|phpunit|phpstan|pint|artisan test|vitest|jest|playwright|eslint|prettier|biome|tsc|rustfmt|pytest|ruff|mypy|pyright)\b'
        .'|\b(npm|pnpm|yarn|bun|composer)\s+(run\s+)?(test|lint|typecheck|type-check|check|format)\b'
        .'|\bcargo\s+(\+\S+\s+)?(test|nextest|clippy|fmt|check)\b/';

    /** The env variable beats `--effort`, so it is removed and the run's own effort applies (ADR 0004). */
    private const NO_EFFORT_ENV = ['CLAUDE_CODE_EFFORT_LEVEL' => false];

    /** The agent's stream-json not yet split into lines. */
    private string $buffer = '';

    /** The `result` event the agent ended on. */
    private ?array $outcome = null;

    /** Whether a rate_limit_event already reported the limit as hit. */
    private bool $limitReported = false;

    /** Whether streamed session ids are saved on the run. */
    private bool $saveSession = true;

    public function agent(Run $run, string $worktree, string $prompt, ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false, bool $saveSession = true): AgentResult
    {
        $repo = $run->repository;

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

        if ($budget !== null) {
            array_push($command, '--max-budget-usd', (string) $budget);
        }

        if ($run->effort) {
            array_push($command, '--effort', $run->effort);
        }

        if ($resume) {
            array_push($command, '--resume', $resume);
        }

        $this->buffer = '';
        $this->outcome = null;
        $this->limitReported = false;
        $this->saveSession = $saveSession;

        $process = Process::path($worktree)
            ->env([...ForemanEnv::forWorktree($worktree), ...$repo->claudeEnv(), ...self::agentEnv($repo->sandbox), ...self::NO_EFFORT_ENV])
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

        $exit = $process->wait();
        $this->consume($run, "\n"); // flush any final partial line

        $run->update(['pid' => null]);

        $this->holdIfOutOfUsage($run);

        // Claude also sends an error result here, so this can't wait for a missing outcome
        if ($resume && $exit->failed() && str_contains($exit->errorOutput(), 'No conversation found')) {
            throw new SessionNotFound("Claude found no conversation for session {$resume}.");
        }

        if ($this->outcome === null && $exit->failed()) {
            throw new RuntimeException('Claude exited without a result: '.str($exit->errorOutput())->limit(500));
        }

        return new AgentResult($this->outcome, $this->outcome['session_id'] ?? null);
    }

    public function oneShot(Repository $repo, string $prompt, ?array $schema = null): OneShotResult
    {
        // Outside the project, so no CLAUDE.md or project settings get loaded; user settings are left out too
        $dir = sys_get_temp_dir().'/foreman-one-shot';
        File::ensureDirectoryExists($dir);

        $command = [
            'claude', '-p', '--model', 'haiku',
            // An answer that follows a schema takes a turn more, and the scope judge is allowed longer than a probe
            '--max-turns', $schema === null ? '1' : '3',
            '--tools', '', '--strict-mcp-config', '--setting-sources', 'project', '--no-session-persistence',
            '--output-format', 'stream-json', '--verbose',
        ];

        if ($schema !== null) {
            array_push($command, '--json-schema', json_encode($schema));
        }

        // The prompt goes on stdin: a diff can outgrow the limit on a single argument
        $process = Process::path($dir)->env([...$repo->claudeEnv(), ...self::NO_EFFORT_ENV])->timeout($schema === null ? 60 : 120)->input($prompt)->run($command);

        $stream = trim($process->output());
        $structured = null;
        $rateLimits = [];
        $usage = [];
        $output = $stream;   // the whole stream, unless a result event turns up

        foreach (explode("\n", $stream) as $line) {
            $event = json_decode($line, true);

            if (($event['type'] ?? null) === 'rate_limit_event') {
                $rateLimits[] = $event['rate_limit_info'] ?? [];
            }

            if (($event['type'] ?? null) === 'result') {
                $output = trim($line);
                $usage = (new AgentResult($event))->usage();
            }

            if (is_array($event['structured_output'] ?? null)) {
                $structured = $event['structured_output'];
            }
        }

        return new OneShotResult(
            $structured,
            $output,
            $process->failed() ? "exit {$process->exitCode()}: ".str(trim($process->errorOutput()) ?: $stream)->limit(500) : null,
            $rateLimits,
            $usage,
        );
    }

    /**
     * Environment that stops the agent pushing: origin's push URL goes nowhere, no credential helper
     * answers, and GitHub tokens are dropped. A false value removes the variable. A sandboxed agent
     * also gets the Sandbox's git settings and environment.
     *
     * @return array<string,string|false>
     */
    public static function agentEnv(bool $sandboxed = false): array
    {
        $config = [
            'remote.origin.pushurl' => '/nonexistent/foreman-agents-cannot-push',
            'credential.helper' => '',
            ...($sandboxed ? Sandbox::gitConfig() : []),
        ];
        $git = ['GIT_CONFIG_COUNT' => (string) count($config)];
        $i = 0;
        foreach ($config as $key => $value) {
            $git["GIT_CONFIG_KEY_{$i}"] = $key;
            $git["GIT_CONFIG_VALUE_{$i}"] = $value;
            $i++;
        }

        return [
            ...$git,
            ...($sandboxed ? Sandbox::env() : []),
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
            'hooks_dir' => $dir,
            'base' => $run->worktree()->baseRef(),
            'verify_script' => trim((string) $run->repository->verify_script),   // empty: the hook uses the detected stacks' checks
            'stop_counter' => "{$dir}/run-{$run->id}-stops",
        ]));
        File::delete("{$dir}/run-{$run->id}-stops");   // each start of the agent, resumes included, gets its own 3 blocks

        $hook = fn (string $name) => implode(' ', array_map('escapeshellarg', [PHP_BINARY, base_path('artisan'), $name, $rules]));
        $config = ['hooks' => [
            'PreToolUse' => [[
                'matcher' => 'Bash|Edit|Write|MultiEdit|NotebookEdit',
                'hooks' => [
                    ['type' => 'command', 'command' => $hook('foreman:guard')],
                    ['type' => 'command', 'command' => $hook('foreman:format-staged')],
                ],
            ]],
            // The checks can take a while, so the timeout (in seconds) is longer than Claude Code's default
            'Stop' => [['hooks' => [['type' => 'command', 'command' => $hook('foreman:stop'), 'timeout' => 1800]]]],
        ]];

        if ($run->repository->sandbox) {
            $config['sandbox'] = Sandbox::settings($worktree, $run->worktree()->gitDirs());
        }

        $settings = "{$dir}/run-{$run->id}-settings.json";
        File::put($settings, json_encode($config));

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

            if ($this->saveSession && isset($event['session_id']) && $run->session_id !== $event['session_id']) {
                $run->update(['session_id' => $event['session_id']]);
            }

            if (($event['type'] ?? null) === 'rate_limit_event') {
                app(Usage::class)->record($run->repository->profileName(), $event['rate_limit_info'] ?? []);
                $this->limitReported = ($event['rate_limit_info']['status'] ?? null) === 'rejected';

                continue;
            }

            if (($event['type'] ?? null) === 'result') {
                $this->outcome = $event;
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
            'Skill' => 'Skill: '.($input['skill'] ?? ''),
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

    /**
     * Running out of usage holds the profile until it resets, instead of failing every queued issue in turn.
     * The rejected rate_limit_event normally does that; this covers Claude stopping without one.
     */
    private function holdIfOutOfUsage(Run $run): void
    {
        $text = ($this->outcome['result'] ?? '').' '.($this->outcome['subtype'] ?? '');

        if (($this->outcome['is_error'] ?? false) && preg_match('/usage limit|rate limit|quota/i', $text)) {
            $profile = $run->repository->profileName();

            if (! $this->limitReported) {
                app(Usage::class)->recordLimitHit($profile);
            }

            $run->log("Usage limit reached. Holding the {$profile} profile until it resets.");
        }
    }
}
