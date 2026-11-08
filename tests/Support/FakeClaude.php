<?php

namespace Tests\Support;

use App\Models\Repository;
use App\Models\Run;
use App\Services\Claude\AgentResult;
use App\Services\Claude\Claude;
use App\Services\Claude\OneShotResult;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Stands in for the `claude` CLI in job tests, so they say what the agent answers and what the job asked of it,
 * not which flags it ran with. ClaudeCli's own tests check the flags.
 */
class FakeClaude implements Claude
{
    /**
     * Every agent pass the job asked for, in order.
     *
     * @var list<array{run:Run,worktree:string,prompt:string,resume:?string,budget:string|float|null,allowLockFiles:bool,saveSession:bool}>
     */
    public array $agentCalls = [];

    /**
     * Every one-shot call, in order.
     *
     * @var list<array{repo:Repository,prompt:string,schema:?array}>
     */
    public array $oneShotCalls = [];

    /** Answers an agent pass with an AgentResult. It gets the call. Without it, the agent ends cleanly. */
    public ?Closure $onAgent = null;

    /** Answers a one-shot call with an OneShotResult, or with the structured answer itself. It gets the call. Without it, the judge passes. */
    public ?Closure $onOneShot = null;

    /** Makes the container hand out this fake wherever the app asks for `Claude`. */
    public static function install(Application $app): self
    {
        $fake = new self;
        $app->instance(Claude::class, $fake);

        return $fake;
    }

    /** The AgentResult for a `result` event, read the way the real adapter reads it. */
    public static function result(array $event = ['type' => 'result', 'num_turns' => 1]): AgentResult
    {
        return new AgentResult($event, $event['session_id'] ?? null);
    }

    public function agent(Run $run, string $worktree, string $prompt, ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false, bool $saveSession = true): AgentResult
    {
        $call = $this->agentCalls[] = compact('run', 'worktree', 'prompt', 'resume', 'budget', 'allowLockFiles', 'saveSession');

        return $this->onAgent ? ($this->onAgent)($call) : self::result();
    }

    public function oneShot(Repository $repo, string $prompt, ?array $schema = null): OneShotResult
    {
        $call = $this->oneShotCalls[] = compact('repo', 'prompt', 'schema');

        $answer = $this->onOneShot
            ? ($this->onOneShot)($call)
            : ['adds_unrequested' => false, 'misses_requested' => false, 'weakens_tests' => false, 'reason' => 'Stays within the issue.'];

        return $answer instanceof OneShotResult ? $answer : new OneShotResult($answer, json_encode($answer));
    }

    /** The first agent pass whose prompt holds `$needle`. */
    public function agentCall(string $needle): ?array
    {
        return collect($this->agentCalls)->first(fn (array $call) => str_contains($call['prompt'], $needle));
    }
}
