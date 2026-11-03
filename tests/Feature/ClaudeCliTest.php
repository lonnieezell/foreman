<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Services\Claude\ClaudeCli;
use App\Support\ForemanEnv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Support\FakeWorktree;
use Tests\TestCase;

/** The real adapter builds the command line and the env. Everything else uses the fake, so these tests are the only ones that read argv. */
class ClaudeCliTest extends TestCase
{
    use RefreshDatabase;

    private const RESULT = '{"type":"result","num_turns":2,"total_cost_usd":0.5,"session_id":"sess-9","usage":{"input_tokens":10,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}}';

    /** @var list<PendingProcess> */
    private array $started = [];

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        FakeWorktree::install($this->app);

        $this->worktree = sys_get_temp_dir().'/foreman-cli-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => 'Read,Edit', 'setup_script' => 'true', 'max_turns' => 40,
            ...$attributes,
        ]);
    }

    private function makeRun(array $repository = [], array $run = []): Run
    {
        return $this->repository($repository)->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            ...$run,
        ]);
    }

    /** Fakes every process as printing `$output`, remembering what started. */
    private function fakeClaude(string $output = self::RESULT, int $exit = 0): void
    {
        Process::fake(function (PendingProcess $process) use ($output, $exit) {
            $this->started[] = $process;

            return Process::result($output, '', $exit);
        });
    }

    /** @return list<string> The agent's command line. */
    private function startAgent(Run $run, string $prompt = 'Do it', ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false): array
    {
        $this->fakeClaude();

        (new ClaudeCli)->agent($run, $this->worktree, $prompt, $resume, $budget, $allowLockFiles);

        return $this->started[0]->command;
    }

    private function after(array $command, string $flag): ?string
    {
        $at = array_search($flag, $command, true);

        return $at === false ? null : $command[$at + 1];
    }

    public function test_the_agent_gets_the_prompt_the_runs_model_and_the_repositorys_limits(): void
    {
        $command = $this->startAgent($this->makeRun(['allowed_tools' => 'Read,Edit', 'max_turns' => 12], ['model' => 'opus']), 'Fix #7');

        $this->assertSame(['claude', '-p', 'Fix #7'], array_slice($command, 0, 3));
        $this->assertSame('opus', $this->after($command, '--model'));
        $this->assertSame('12', $this->after($command, '--max-turns'));
        $this->assertSame('Read,Edit', $this->after($command, '--allowedTools'));
        $this->assertSame('dontAsk', $this->after($command, '--permission-mode'));
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

    public function test_an_error_result_is_a_failed_pass(): void
    {
        $this->fakeClaude('{"type":"result","is_error":true,"subtype":"error_max_turns","result":"Out of turns."}');

        $agent = (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it');

        $this->assertTrue($agent->failed());
        $this->assertSame('error_max_turns', $agent->subtype());
        $this->assertSame('Out of turns.', $agent->message());
    }

    public function test_exiting_without_a_result_throws(): void
    {
        $this->fakeClaude('', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Claude exited without a result');

        (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it');
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
}
