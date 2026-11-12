<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\SessionNotFound;
use App\Support\ForemanEnv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Support\ClaudeCliHarness;
use Tests\TestCase;

/** The real adapter builds the command line and the env. Everything else uses the fake, so these tests are the only ones that read argv. */
class ClaudeCliTest extends TestCase
{
    use ClaudeCliHarness, RefreshDatabase;

    /** @return list<string> The agent's command line. */
    private function startAgent(Run $run, string $prompt = 'Do it', ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false): array
    {
        $this->fakeClaude();

        (new ClaudeCli)->agent($run, $this->worktree, $prompt, $resume, $budget, $allowLockFiles);

        return $this->started[0]->command;
    }

    public function test_the_agent_gets_the_prompt_on_stdin_the_runs_model_and_the_repositorys_limits(): void
    {
        $command = $this->startAgent($this->makeRun(['allowed_tools' => 'Read,Edit', 'max_turns' => 12], ['model' => 'opus']), 'Fix #7');

        // A live session: the prompt is the first user message, and stdin stays open for the operator's
        $this->assertSame(['claude', '-p'], array_slice($command, 0, 2));
        $this->assertNotContains('Fix #7', $command);
        $this->assertSame([['type' => 'user', 'message' => ['role' => 'user', 'content' => 'Fix #7']]], $this->sentMessages());
        $this->assertSame('stream-json', $this->after($command, '--input-format'));
        $this->assertContains('--replay-user-messages', $command);
        $this->assertSame('opus', $this->after($command, '--model'));
        $this->assertSame('12', $this->after($command, '--max-turns'));
        $this->assertSame('Read,Edit', $this->after($command, '--allowedTools'));
        // A call off the allowlist asks Foreman over stdin, instead of being denied (ADR 0010)
        $this->assertSame('manual', $this->after($command, '--permission-mode'));
        $this->assertSame('host', $this->after($command, '--permission-prompts'));
        $this->assertSame('stdio', $this->after($command, '--permission-prompt-tool'));
        $this->assertSame('stream-json', $this->after($command, '--output-format'));
        $this->assertContains('--verbose', $command);
    }

    public function test_effort_is_passed_only_when_the_run_has_one(): void
    {
        $this->assertNotContains('--effort', $this->startAgent($this->makeRun()));

        $this->started = [];
        $this->assertSame('high', $this->after($this->startAgent($this->makeRun(run: ['effort' => 'high'])), '--effort'));
    }

    public function test_the_budget_is_passed_only_when_there_is_one(): void
    {
        $this->assertNotContains('--max-budget-usd', $this->startAgent($this->makeRun()));

        $this->started = [];
        $this->assertSame('2.50', $this->after($this->startAgent($this->makeRun(), budget: '2.50'), '--max-budget-usd'));

        $this->started = [];
        $this->assertSame('2', $this->after($this->startAgent($this->makeRun(), budget: 2.0), '--max-budget-usd'));
    }

    public function test_a_session_is_resumed_only_when_one_is_given(): void
    {
        $this->assertNotContains('--resume', $this->startAgent($this->makeRun()));

        $this->started = [];
        $this->assertSame('sess-1', $this->after($this->startAgent($this->makeRun(), resume: 'sess-1'), '--resume'));
    }

    public function test_the_hook_settings_sit_outside_the_worktree_and_have_no_sandbox_by_default(): void
    {
        $command = $this->startAgent($this->makeRun());
        $settings = json_decode(File::get($this->after($command, '--settings')), true);

        $this->assertStringStartsWith(storage_path('foreman/hooks'), $this->after($command, '--settings'));
        $this->assertArrayHasKey('PreToolUse', $settings['hooks']);
        $this->assertArrayHasKey('Stop', $settings['hooks']);
        $this->assertArrayNotHasKey('sandbox', $settings);
    }

    public function test_the_hook_rules_say_whether_lock_files_may_change(): void
    {
        $run = $this->makeRun();
        $this->startAgent($run, allowLockFiles: true);
        $rules = json_decode(File::get(storage_path("foreman/hooks/run-{$run->id}-rules.json")), true);

        $this->assertTrue($rules['allow_lock_files']);
        $this->assertSame($this->worktree, $rules['worktree']);
    }

    public function test_a_sandboxed_agent_gets_the_sandbox_in_its_settings_and_env(): void
    {
        $command = $this->startAgent($this->makeRun(['sandbox' => true]));
        $settings = json_decode(File::get($this->after($command, '--settings')), true);
        $env = $this->started[0]->environment;

        $this->assertTrue($settings['sandbox']['enabled']);
        $this->assertSame(storage_path('foreman/cache/npm'), $env['npm_config_cache']);
        $this->assertSame('3', $env['GIT_CONFIG_COUNT']);
    }

    public function test_the_agent_cannot_push_and_has_no_github_tokens(): void
    {
        $this->startAgent($this->makeRun());
        $env = $this->started[0]->environment;

        $this->assertSame('remote.origin.pushurl', $env['GIT_CONFIG_KEY_0']);
        $this->assertSame('2', $env['GIT_CONFIG_COUNT']);
        $this->assertFalse($env['GH_TOKEN']);
        $this->assertFalse($env['GITHUB_TOKEN']);
        $this->assertFalse($env['SSH_AUTH_SOCK']);
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
    }

    public function test_foremans_own_env_is_removed_and_the_repositorys_profile_is_set(): void
    {
        config(['foreman.claude_profiles' => ['work' => '/home/me/.claude-work']]);
        $this->startAgent($this->makeRun(['claude_profile' => 'work']));
        $env = $this->started[0]->environment;

        $this->assertSame('/home/me/.claude-work', $env['CLAUDE_CONFIG_DIR']);

        foreach (array_keys(ForemanEnv::removed()) as $name) {
            $this->assertFalse($env[$name], "{$name} should be removed");
        }
    }

    public function test_the_agent_runs_in_the_worktree(): void
    {
        $this->startAgent($this->makeRun());

        $this->assertSame($this->worktree, $this->started[0]->path);
    }

    public function test_the_result_gives_back_the_outcome_the_usage_and_the_session(): void
    {
        $this->fakeClaude();
        $run = $this->makeRun();

        $agent = (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertSame(2, $agent->outcome['num_turns']);
        $this->assertSame('sess-9', $agent->sessionId);
        $this->assertSame('sess-9', $run->fresh()->session_id);
        $this->assertSame(['cost_usd' => 0.5, 'num_turns' => 2, 'input_tokens' => 10, 'output_tokens' => 20, 'cache_read_tokens' => 30, 'cache_write_tokens' => 40], $agent->usage());
        $this->assertFalse($agent->failed());
        $this->assertSame(0.5, $agent->cost());
    }

    public function test_a_pass_that_keeps_no_session_leaves_the_runs_session_id_alone(): void
    {
        $this->fakeClaude();
        $run = $this->makeRun(run: ['session_id' => 'sess-1']);

        $agent = (new ClaudeCli)->agent($run, $this->worktree, 'Review it', saveSession: false);

        $this->assertSame('sess-9', $agent->sessionId);
        $this->assertSame('sess-1', $run->fresh()->session_id);
    }

    public function test_an_error_result_is_a_failed_pass(): void
    {
        $this->fakeClaude('{"type":"result","is_error":true,"subtype":"error_max_turns","result":"Out of turns."}');

        $agent = (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it');

        $this->assertTrue($agent->failed());
        $this->assertSame('error_max_turns', $agent->subtype());
        $this->assertSame('Out of turns.', $agent->message());
    }

    public function test_a_skill_call_is_logged_with_the_skills_name(): void
    {
        $this->fakeClaude('{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Skill","input":{"skill":"code-review"}}]}}'."\n".self::RESULT);
        $run = $this->makeRun();

        (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertStringContainsString('Skill: code-review', File::get($run->logPath()));
    }

    public function test_exiting_without_a_result_throws(): void
    {
        $this->fakeClaude('', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Claude exited without a result');

        (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it');
    }

    public function test_a_resume_with_no_conversation_for_the_session_throws_session_not_found(): void
    {
        // What claude 2.1 prints: the reason on stderr, and an error result on stdout
        $result = '{"type":"result","subtype":"error_during_execution","is_error":true,"num_turns":0,"session_id":"sess-gone","errors":["No conversation found with session ID: sess-gone"]}';
        Process::fake(fn () => Process::result($result, 'No conversation found with session ID: sess-gone', 1));

        $this->expectException(SessionNotFound::class);

        (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it', 'sess-gone');
    }

    public function test_any_other_failed_exit_is_a_plain_failure(): void
    {
        Process::fake(fn () => Process::result('', 'Something else broke', 1));

        try {
            (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it', 'sess-1');
            $this->fail('Expected the agent to throw.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(SessionNotFound::class, $e);
            $this->assertStringContainsString('Claude exited without a result', $e->getMessage());
        }
    }

    public function test_a_one_shot_is_haiku_with_no_tools_no_session_and_no_project(): void
    {
        $this->fakeClaude();
        (new ClaudeCli)->oneShot($this->repository(), 'ok');
        $call = $this->started[0];

        $this->assertSame(['claude', '-p', '--model', 'haiku'], array_slice($call->command, 0, 4));
        $this->assertSame('', $this->after($call->command, '--tools'));
        $this->assertSame('1', $this->after($call->command, '--max-turns'));
        $this->assertSame('project', $this->after($call->command, '--setting-sources'));
        $this->assertContains('--strict-mcp-config', $call->command);
        $this->assertContains('--no-session-persistence', $call->command);
        $this->assertSame('stream-json', $this->after($call->command, '--output-format'));
        $this->assertNotContains('--json-schema', $call->command);
        $this->assertNotContains('--resume', $call->command);
        $this->assertNotContains('--settings', $call->command);
        $this->assertSame(sys_get_temp_dir().'/foreman-one-shot', $call->path);
    }

    public function test_a_one_shot_sends_the_prompt_on_stdin_and_uses_the_repositorys_profile(): void
    {
        config(['foreman.claude_profiles' => ['work' => '/home/me/.claude-work']]);
        $this->fakeClaude();

        (new ClaudeCli)->oneShot($this->repository(['claude_profile' => 'work']), 'a very long diff');
        $call = $this->started[0];

        $this->assertSame('a very long diff', $call->input);
        $this->assertNotContains('a very long diff', $call->command);
        $this->assertSame('/home/me/.claude-work', $call->environment['CLAUDE_CONFIG_DIR']);
    }

    public function test_a_one_shot_with_a_schema_asks_for_it_and_reads_the_structured_answer(): void
    {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
        $this->fakeClaude('{"type":"result","is_error":false,"structured_output":{"ok":true}}');

        $answer = (new ClaudeCli)->oneShot($this->repository(), 'judge this', $schema);

        $this->assertSame(json_encode($schema), $this->after($this->started[0]->command, '--json-schema'));
        $this->assertSame('3', $this->after($this->started[0]->command, '--max-turns'));
        $this->assertSame(['ok' => true], $answer->structured);
        $this->assertNull($answer->error);
    }

    public function test_a_one_shot_gives_back_the_rate_limits_claude_reported(): void
    {
        $this->fakeClaude("{\"type\":\"rate_limit_event\",\"rate_limit_info\":{\"status\":\"allowed\"}}\n{\"type\":\"result\"}");

        $answer = (new ClaudeCli)->oneShot($this->repository(), 'ok');

        $this->assertSame([['status' => 'allowed']], $answer->rateLimits);
        $this->assertNull($answer->structured);
    }

    public function test_a_one_shots_output_is_its_result_event_not_the_whole_stream(): void
    {
        $result = '{"type":"result","is_error":true,"subtype":"error_max_turns"}';
        $this->fakeClaude("{\"type\":\"system\",\"subtype\":\"init\"}\n{$result}");

        $answer = (new ClaudeCli)->oneShot($this->repository(), 'judge this', ['type' => 'object']);

        $this->assertSame($result, $answer->output);
    }

    public function test_a_one_shot_that_fails_says_why(): void
    {
        Process::fake(['*' => Process::result('', 'API Error: overloaded', 1)]);

        $answer = (new ClaudeCli)->oneShot($this->repository(), 'ok');

        $this->assertSame('exit 1: API Error: overloaded', $answer->error);
        $this->assertNull($answer->structured);
    }

    public function test_the_effort_env_variable_is_removed_because_it_beats_the_effort_flag(): void
    {
        $this->startAgent($this->makeRun(run: ['effort' => 'high']));
        (new ClaudeCli)->oneShot($this->repository(['github_repo' => 'owner/other']), 'ok');

        $this->assertFalse($this->started[0]->environment['CLAUDE_CODE_EFFORT_LEVEL']);
        $this->assertFalse($this->started[1]->environment['CLAUDE_CODE_EFFORT_LEVEL']);
    }

    public function test_a_one_shot_gives_back_what_it_spent(): void
    {
        $this->fakeClaude(self::RESULT);

        $answer = (new ClaudeCli)->oneShot($this->repository(), 'ok');

        $this->assertSame(['cost_usd' => 0.5, 'num_turns' => 2, 'input_tokens' => 10, 'output_tokens' => 20, 'cache_read_tokens' => 30, 'cache_write_tokens' => 40], $answer->usage);
    }
}
