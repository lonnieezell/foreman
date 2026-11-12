<?php

namespace App\Services\Claude;

use App\Enums\Outcome;
use App\Enums\RunStatus;
use App\Guardrails\Sandbox;
use App\Models\PermissionRequest;
use App\Models\Repository;
use App\Models\Run;
use App\Models\RunMessage;
use App\Services\AgentReport;
use App\Services\Usage;
use App\Support\ForemanEnv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Process\InputStream;

/** The real `claude` CLI. The only place that builds its command line. */
class ClaudeCli implements Claude
{
    /** A Bash command matching this moves the run to Checking: tests, linters, formatters, type checks. */
    public const CHECK_COMMAND = '/\b(pest|phpunit|phpstan|pint|artisan test|vitest|jest|playwright|eslint|prettier|biome|tsc|rustfmt|pytest|ruff|mypy|pyright)\b'
        .'|\b(npm|pnpm|yarn|bun|composer)\s+(run\s+)?(test|lint|typecheck|type-check|check|format)\b'
        .'|\bcargo\s+(\+\S+\s+)?(test|nextest|clippy|fmt|check)\b/';

    /** Starts each operator message Foreman sends into a live session. */
    private const OPERATOR_PREFIX = "Your operator sent this from Foreman:\n\n";

    /** Starts an operator message sent after the agent's turn ended, such as the answer to its question. */
    private const AFTER_TURN_PREFIX = "Your operator replied from Foreman. Foreman cleared .foreman/result.json, so write it again before you stop.\n\nTheir message:\n\n";

    /** The env variable beats `--effort`, so it is removed and the run's own effort applies (ADR 0004). */
    private const NO_EFFORT_ENV = ['CLAUDE_CODE_EFFORT_LEVEL' => false];

    /** The agent's stream-json not yet split into lines. */
    private string $buffer = '';

    /** The `result` event the agent ended on. */
    private ?array $outcome = null;

    /** Whether a rate_limit_event already reported the limit as hit. */
    private bool $limitReported = false;

    /** Whether the agent ended its turn and nothing has been sent to it since. */
    private bool $turnEnded = false;

    /** @var list<string|null> What the CLI will echo back next, oldest first: null for the prompt, then each operator message. */
    private array $echoes = [];

    /** Whether streamed session ids are saved on the run. */
    private bool $saveSession = true;

    public function agent(Run $run, string $worktree, string $prompt, ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false, bool $saveSession = true, int $questionWindow = 0): AgentResult
    {
        $repo = $run->repository;

        $command = [
            // A live session: the prompt is the first message on stdin, which stays open for the operator's (ADR 0009)
            'claude', '-p', '--input-format', 'stream-json', '--replay-user-messages',
            '--model', $run->model,
            '--max-turns', (string) $repo->max_turns,
            '--allowedTools', $repo->allowed_tools,
            // A call off the allowlist comes to Foreman as a `can_use_tool` request on stdout, for the operator (ADR 0010)
            '--permission-mode', 'manual', '--permission-prompts', 'host', '--permission-prompt-tool', 'stdio',
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
        $this->turnEnded = false;
        $this->echoes = [null];
        $this->saveSession = $saveSession;
        // A request an earlier session left can't be answered: the CLI that asked is gone (ADR 0010)
        $run->permissionRequests()->whereNull('sent_at')->delete();

        $stdin = new InputStream;
        $stdin->write(self::userMessage($prompt));

        $process = Process::path($worktree)
            ->env([...ForemanEnv::forWorktree($worktree, $repo->toolchain_path), ...$repo->claudeEnv(), ...self::agentEnv($repo->sandbox), ...self::NO_EFFORT_ENV])
            ->forever()
            ->input($stdin)
            ->start($command, fn (string $type, string $output) => $type === 'out' ? $this->consume($run, $output) : null);

        $run->update(['pid' => $process->id()]);
        $deadline = now()->addMinutes($repo->max_minutes);
        $questionsKept = false;   // the last turn's questions went in the thread while the session waited for an answer

        while ($process->running()) {
            Sleep::for(2)->seconds();
            $run->update(['heartbeat_at' => now()]);

            if ($run->fresh()->cancel_requested) {
                $process->signal(15); // SIGTERM
                $process->wait();
                throw new RuntimeException('Cancelled');
            }

            // Mid-turn, the CLI holds a message until the current tool call ends; after a turn, it starts another
            $messages = $stdin->isClosed() ? collect() : $run->unsentReplies()->get();

            if ($messages->isNotEmpty()) {
                if ($this->turnEnded) {
                    // The message starts a new turn, which must end on a result file of its own
                    File::delete("{$worktree}/".AgentReport::RESULT_FILE);
                    $run->update(['question_until' => null]);
                }

                $this->send($stdin, $messages, $this->turnEnded ? self::AFTER_TURN_PREFIX : self::OPERATOR_PREFIX);
                $questionsKept = $this->turnEnded = false;
            } elseif ($this->turnEnded && ! $stdin->isClosed()) {
                if ($run->question_until === null) {
                    $questionsKept = $this->openQuestionWindow($run, $worktree, $questionWindow, $deadline);
                }

                // The CLI waits for another message after each turn, so closing stdin is what ends the session
                if (! $run->question_until?->isFuture()) {
                    if ($questionsKept) {
                        // The result file still holds the question, so the run goes on to the usual question flow
                        $run->log("No answer within {$questionWindow} min. Ending the session on the question.");
                    }

                    // A message posted from now on waits on the run, so the page stops offering to send it
                    $run->update(['question_until' => null, 'pid' => null]);
                    $stdin->close();
                }
            }

            $this->answerPermissions($run, $stdin);

            // The question window ends at the deadline, so stdin is closed by then, and a closing session gets a minute to exit
            if (now()->greaterThan($stdin->isClosed() ? $deadline->copy()->addMinute() : $deadline)) {
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

        return new AgentResult($this->outcome, $this->outcome['session_id'] ?? null, $questionsKept);
    }

    /**
     * Write the operator's messages to the session, oldest first.
     *
     * @param  Collection<int,RunMessage>  $messages
     */
    private function send(InputStream $stdin, Collection $messages, string $prefix): void
    {
        foreach ($messages as $message) {
            // Trusted operator text: Foreman runs on localhost with no login (ADR 0001)
            $stdin->write(self::userMessage($prefix.$message->body));
            $this->echoes[] = $message->body;
        }

        RunMessage::whereKey($messages->modelKeys())->update(['sent_at' => now()]);
    }

    /** Deny the calls nobody answered in time, then send the session every answer it hasn't had (ADR 0010). */
    private function answerPermissions(Run $run, InputStream $stdin): void
    {
        if ($stdin->isClosed()) {
            return;
        }

        foreach ($run->permissionRequests()->whereNull('sent_at')->get() as $request) {
            if ($request->decision === null && $request->expires_at->isPast()) {
                $request->decide(PermissionRequest::TIMEOUT);
            }

            if ($request->decision === null) {
                continue;   // still waiting for the operator
            }

            $stdin->write($request->controlResponse());
            $request->update(['sent_at' => now()]);
            $run->log($request->decisionLine());
        }
    }

    /**
     * When the agent's turn ended on a question, keep its questions in the thread and hold the session open for an answer,
     * until the window or the run's time limit ends. Gives whether it did.
     */
    private function openQuestionWindow(Run $run, string $worktree, int $minutes, Carbon $deadline): bool
    {
        $outcome = (new AgentReport($worktree))->outcome();

        if ($minutes < 1 || $outcome?->kind !== Outcome::Question) {
            return false;
        }

        $run->keepQuestions($outcome->questions);
        $run->update(['question_until' => now()->addMinutes($minutes)->min($deadline)]);

        $count = count($outcome->questions);
        $run->log("Agent asked {$count} ".str('question')->plural($count).". Waiting up to {$minutes} min for your answer.");

        return true;
    }

    /** One user message in the CLI's stream-json input format, as a line. */
    private static function userMessage(string $text): string
    {
        return json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $text]], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
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
            'toolchain_path' => $run->repository->toolchain_path,
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

            // A call off the allowlist waits for the operator, and the loop sends the answer (ADR 0010)
            PermissionRequest::record($run, $event);

            if (($event['type'] ?? null) === 'result') {
                $this->outcome = self::addEarlierTurns($event, $this->outcome);
                $this->turnEnded = true;
                $run->log(sprintf('Finished after %d turns, $%.2f', $event['num_turns'] ?? 0, $event['total_cost_usd'] ?? 0));

                continue;
            }

            // The CLI echoes each message it takes from stdin, in the order they were sent; the first is the prompt
            if (($event['type'] ?? null) === 'user' && ($event['isReplay'] ?? false)) {
                if (($body = array_shift($this->echoes)) !== null) {
                    $run->log('You: '.str($body)->squish()->limit(300));
                }

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

    /**
     * A later turn's `result` event with the earlier turns' counts added. The CLI reports the session's total cost,
     * but the turns and tokens of that turn alone.
     */
    private static function addEarlierTurns(array $event, ?array $earlier): array
    {
        if ($earlier === null) {
            return $event;
        }

        $event['num_turns'] = ($event['num_turns'] ?? 0) + ($earlier['num_turns'] ?? 0);

        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
            $event['usage'][$key] = ($event['usage'][$key] ?? 0) + ($earlier['usage'][$key] ?? 0);
        }

        return $event;
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
