<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Models\Run;
use App\Services\AgentReport;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class ToolchainPathTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLCHAIN = '/opt/php82/bin:/opt/node20/bin';

    private string $dir;

    private ?Run $run = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-toolchain-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/.git");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(storage_path('foreman/worktrees/owner-toolchain-test'));

        if ($this->run) {
            File::delete([$this->run->logPath(), $this->run->rawLogPath()]);
            File::delete(glob(storage_path("foreman/hooks/run-{$this->run->id}-*")));
        }

        parent::tearDown();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => $this->dir, 'github_repo' => 'owner/toolchain-test', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'make deps', 'base_branch' => 'main',
            'ready_label' => 'ready-for-agent', 'working_label' => 'agent-working', 'human_label' => 'needs-human',
            'cleanup_worktrees' => true, 'watch_prs' => false, 'verify_script' => 'make check',
            'model' => 'sonnet', 'concurrency' => 1, 'poll_interval_minutes' => 10, 'max_turns' => 40, 'max_minutes' => 45,
            ...$attributes,
        ]);
    }

    private function runJob(?string $toolchain): Run
    {
        $repo = $this->repository(['toolchain_path' => $toolchain]);
        $this->run = $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
        ]);

        Process::fake(function (PendingProcess $process) use ($repo) {
            if ($process->command[0] === 'claude' && ! in_array('--json-schema', $process->command, true)) {
                File::ensureDirectoryExists(dirname("{$repo->worktreePath(7)}/".AgentReport::RESULT_FILE));
                File::put("{$repo->worktreePath(7)}/".AgentReport::RESULT_FILE, json_encode(['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.']));

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 1]));
            }

            return Process::result('');
        });

        (new ProcessRun($run))->handle(Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        return $run;
    }

    /** @return array<string, array{\Closure(PendingProcess): bool}> */
    public static function worktreeProcesses(): array
    {
        return [
            'the setup script' => [fn (PendingProcess $p) => $p->command === ['bash', '-ec', 'make deps']],
            'the agent' => [fn (PendingProcess $p) => $p->command[0] === 'claude' && in_array('stream-json', $p->command, true)],
            'the gate\'s verify script' => [fn (PendingProcess $p) => $p->command === ['bash', '-ec', 'make check']],
        ];
    }

    #[DataProvider('worktreeProcesses')]
    public function test_the_toolchain_goes_in_front_of_path(\Closure $matches): void
    {
        $this->runJob(self::TOOLCHAIN);

        Process::assertRan(fn (PendingProcess $p) => $matches($p)
            && ($p->environment['PATH'] ?? null) === self::TOOLCHAIN.':'.getenv('PATH'));
    }

    #[DataProvider('worktreeProcesses')]
    public function test_without_a_toolchain_path_is_left_alone(\Closure $matches): void
    {
        $this->runJob(null);

        Process::assertRan(fn (PendingProcess $p) => $matches($p) && ! array_key_exists('PATH', $p->environment));
    }

    public function test_the_gates_integrity_check_keeps_foremans_own_path(): void
    {
        $this->runJob(self::TOOLCHAIN);

        Process::assertRan(fn (PendingProcess $p) => ($p->command[0] ?? null) === PHP_BINARY
            && str_contains($p->command[1] ?? '', 'test-integrity.php')
            && ! array_key_exists('PATH', $p->environment));
    }

    public function test_the_run_log_shows_the_toolchain_once_at_the_start(): void
    {
        $run = $this->runJob(self::TOOLCHAIN);
        $log = file_get_contents($run->logPath());

        $this->assertSame(1, substr_count($log, self::TOOLCHAIN));
        $this->assertLessThan(strpos($log, 'Running setup script'), strpos($log, 'Toolchain path: '.self::TOOLCHAIN));
    }

    public function test_the_run_log_says_nothing_without_a_toolchain(): void
    {
        $run = $this->runJob(null);

        $this->assertStringNotContainsString('Toolchain path', file_get_contents($run->logPath()));
    }

    public function test_the_hook_rules_carry_the_toolchain(): void
    {
        $run = $this->runJob(self::TOOLCHAIN);

        $rules = json_decode(file_get_contents(storage_path("foreman/hooks/run-{$run->id}-rules.json")), true);
        $this->assertSame(self::TOOLCHAIN, $rules['toolchain_path']);
    }

    public function test_the_stop_hook_runs_the_verify_script_with_the_toolchain_from_its_rules(): void
    {
        $work = "{$this->dir}/work";
        File::ensureDirectoryExists($work);
        foreach ([['init', '-q', '-b', 'main'], ['-c', 'user.name=T', '-c', 'user.email=t@example.test', 'commit', '-q', '--allow-empty', '-m', 'base']] as $args) {
            (new SymfonyProcess(['git', ...$args], $work))->mustRun();
        }

        $rules = "{$this->dir}/rules.json";
        $writeRules = fn (?string $toolchain) => File::put($rules, json_encode([
            'worktree' => $work, 'log' => "{$this->dir}/run.log", 'base' => 'main', 'stop_counter' => "{$this->dir}/stops",
            'locked_paths' => [], 'allow_lock_files' => false, 'toolchain_path' => $toolchain,
            'verify_script' => 'case "$PATH" in '.self::TOOLCHAIN.':*) ;; *) echo "PATH is $PATH"; exit 1;; esac',
        ]));

        $writeRules(self::TOOLCHAIN);
        $this->artisan('foreman:stop', ['rules' => $rules])->assertExitCode(0);

        $writeRules(null);
        $this->artisan('foreman:stop', ['rules' => $rules])->assertExitCode(2);
    }

    public function test_the_form_saves_the_toolchain_with_home_expanded(): void
    {
        // A stand-in home, so the test never writes to the real one
        $home = getenv('HOME');
        putenv("HOME={$this->dir}/home");
        File::ensureDirectoryExists("{$this->dir}/home/bin");
        File::ensureDirectoryExists("{$this->dir}/php82");
        $repo = $this->repository();

        try {
            Livewire::test(RepositoryForm::class, ['repository' => $repo])
                ->assertSeeHtml('id="toolchain_path"')
                ->set('toolchain_path', "~/bin:{$this->dir}/php82")
                ->call('save', Mockery::mock(GitHub::class)->shouldIgnoreMissing())
                ->assertHasNoErrors();
        } finally {
            putenv($home === false ? 'HOME' : "HOME={$home}");
        }

        $this->assertSame("{$this->dir}/home/bin:{$this->dir}/php82", $repo->fresh()->toolchain_path);
    }

    /** @return array<string, array{string, string}> `{dir}` stands for the test's own folder. */
    public static function badToolchains(): array
    {
        return [
            'a folder that does not exist' => ['{dir}:{dir}/missing', 'There’s no folder at {dir}/missing.'],
            // vendor/bin exists relative to Foreman's own folder, but not in the worktrees
            'a relative folder' => ['{dir}:vendor/bin', 'Use a full path for vendor/bin.'],
        ];
    }

    #[DataProvider('badToolchains')]
    public function test_the_form_rejects_a_folder_it_cannot_use(string $toolchain, string $error): void
    {
        $repo = $this->repository(['toolchain_path' => '/opt/old']);

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->assertSet('toolchain_path', '/opt/old')
            ->set('toolchain_path', str_replace('{dir}', $this->dir, $toolchain))
            ->call('save', Mockery::mock(GitHub::class)->shouldIgnoreMissing())
            ->assertHasErrors('toolchain_path')
            ->assertSee(str_replace('{dir}', $this->dir, $error));

        $this->assertSame('/opt/old', $repo->fresh()->toolchain_path);
    }

    public function test_an_empty_toolchain_is_saved_as_none(): void
    {
        $repo = $this->repository(['toolchain_path' => $this->dir]);

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('toolchain_path', '  ')
            ->call('save', Mockery::mock(GitHub::class)->shouldIgnoreMissing())
            ->assertHasNoErrors();

        $this->assertNull($repo->fresh()->toolchain_path);
    }
}
