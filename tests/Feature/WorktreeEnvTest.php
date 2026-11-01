<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use App\Support\ForemanEnv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class WorktreeEnvTest extends TestCase
{
    use RefreshDatabase;

    private string $envDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Stands in for Foreman's own .env, so the tests don't depend on the real one
        $this->envDir = sys_get_temp_dir().'/foreman-env-'.uniqid();
        File::ensureDirectoryExists($this->envDir);
        File::put("{$this->envDir}/.env", "APP_KEY=base64:foremans-own-key\nFOREMAN_ENV_PROBE=from-foreman\n");
        $this->app->useEnvironmentPath($this->envDir);
        $this->app->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->envDir);
        File::deleteDirectory(storage_path('foreman/worktrees/owner-env-test'));
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function runJob(): void
    {
        $run = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/env-test', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'cp .env.example .env',
            'verify_script' => 'composer test', 'watch_prs' => false,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
        ]);

        Process::fake(function (PendingProcess $process) use ($run) {
            if ($process->command[0] === 'claude' && ! in_array('--json-schema', $process->command, true)) {
                File::ensureDirectoryExists(dirname("{$run->repository->worktreePath(7)}/".ProcessRun::RESULT_FILE));
                File::put("{$run->repository->worktreePath(7)}/".ProcessRun::RESULT_FILE, json_encode(['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.']));

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 1]));
            }

            return Process::result('');
        });

        (new ProcessRun($run))->handle(Mockery::mock(GitHub::class)->shouldIgnoreMissing());
    }

    /** A git worktree with one commit on main, and a hook rules file for it. Returns the rules file's path. */
    private function hookRules(string $verify): string
    {
        $work = "{$this->envDir}/work";
        File::ensureDirectoryExists($work);

        foreach ([['init', '-q', '-b', 'main'], ['-c', 'user.name=T', '-c', 'user.email=t@example.test', 'commit', '-q', '--allow-empty', '-m', 'base']] as $args) {
            (new SymfonyProcess(['git', ...$args], $work))->mustRun();
        }

        File::put("{$this->envDir}/rules.json", json_encode([
            'worktree' => $work, 'log' => "{$this->envDir}/run.log", 'base' => 'main', 'stop_counter' => "{$this->envDir}/stops",
            'locked_paths' => [], 'allow_lock_files' => false, 'verify_script' => $verify,
        ]));

        return "{$this->envDir}/rules.json";
    }

    /** True when the process was started without the variables from Foreman's .env. */
    private static function withoutForemansEnv(PendingProcess $p): bool
    {
        return ($p->environment['APP_KEY'] ?? null) === false
            && ($p->environment['FOREMAN_ENV_PROBE'] ?? null) === false;
    }

    public function test_the_setup_script_runs_without_foremans_env(): void
    {
        $this->runJob();

        Process::assertRan(fn (PendingProcess $p) => $p->command === ['bash', '-ec', 'cp .env.example .env'] && self::withoutForemansEnv($p));
    }

    public function test_the_agent_runs_without_foremans_env_and_still_cannot_push(): void
    {
        $this->runJob();

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'claude' && in_array('stream-json', $p->command, true)
            && self::withoutForemansEnv($p)
            && $p->environment['GIT_CONFIG_VALUE_0'] === '/nonexistent/foreman-agents-cannot-push'
            && $p->environment['GH_TOKEN'] === false);
    }

    public function test_the_gates_checks_run_without_foremans_env(): void
    {
        $this->runJob();

        Process::assertRan(fn (PendingProcess $p) => str_contains($p->command[1] ?? '', 'test-integrity.php') && self::withoutForemansEnv($p));
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['bash', '-ec', 'composer test'] && self::withoutForemansEnv($p));
    }

    public function test_the_stop_hooks_checks_run_without_foremans_env(): void
    {
        // As Laravel does when it loads .env, so a child process would inherit the variable
        putenv('FOREMAN_ENV_PROBE=from-foreman');
        $_ENV['FOREMAN_ENV_PROBE'] = $_SERVER['FOREMAN_ENV_PROBE'] = 'from-foreman';

        try {
            $rules = $this->hookRules('test -z "${FOREMAN_ENV_PROBE:-}" || { echo "leaked: $FOREMAN_ENV_PROBE"; exit 1; }');

            $this->artisan('foreman:stop', ['rules' => $rules])->assertExitCode(0);
        } finally {
            putenv('FOREMAN_ENV_PROBE');
            unset($_ENV['FOREMAN_ENV_PROBE'], $_SERVER['FOREMAN_ENV_PROBE']);
        }
    }

    public function test_the_hooks_still_run_in_the_agents_environment(): void
    {
        $rules = $this->hookRules('true');

        // What the agent's hooks inherit: Foreman's real .env removed, so each hook must load it again itself
        $this->app->useEnvironmentPath(base_path());
        $env = [...ForemanEnv::removed(), ...ProcessRun::agentEnv()];

        foreach (['foreman:guard', 'foreman:format-staged', 'foreman:stop'] as $hook) {
            $process = new SymfonyProcess([PHP_BINARY, base_path('artisan'), $hook, $rules], "{$this->envDir}/work", $env);
            $process->setInput(json_encode(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']]));
            $process->run();

            $this->assertSame(0, $process->getExitCode(), "{$hook} failed: {$process->getErrorOutput()}{$process->getOutput()}");
        }
    }
}
