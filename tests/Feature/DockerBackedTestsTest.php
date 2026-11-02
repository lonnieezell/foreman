<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\FakeWorktree;
use Tests\TestCase;

class DockerBackedTestsTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    private FakeWorktree $git;

    /** @var list<string> The first words of each command Foreman ran, in order. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = FakeWorktree::install($this->app);
        $this->git->onRemove = fn () => $this->ran[] = 'worktree remove';
        $this->worktree = sys_get_temp_dir().'/foreman-docker-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
        $this->fakeProcesses();
    }

    private function fakeProcesses(int $dockerExitCode = 0): void
    {
        $this->ran = [];

        Process::fake(function (PendingProcess $process) use ($dockerExitCode) {
            $this->ran[] = implode(' ', array_slice($process->command, 0, 3));

            return Process::result(errorOutput: $dockerExitCode ? 'daemon not running' : '', exitCode: $process->command[0] === 'docker' ? $dockerExitCode : 0);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function makeRun(array $repository = []): Run
    {
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            ...$repository,
        ]);

        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::PrOpened,
            'worktree_path' => $this->worktree,
        ]);
    }

    public function test_compose_goes_down_before_the_worktree_is_removed(): void
    {
        File::put("{$this->worktree}/docker-compose.yml", 'services: {}');

        $this->makeRun(['stop_docker' => true])->removeWorktree();

        Process::assertRan(fn (PendingProcess $p) => $p->command === ['docker', 'compose', 'down', '-v'] && $p->path === $this->worktree);
        $this->assertSame(['docker compose down', 'worktree remove'], $this->ran);
    }

    public function test_every_compose_file_name_counts(): void
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $file) {
            File::put("{$this->worktree}/{$file}", 'services: {}');
            $this->fakeProcesses();

            $this->makeRun(['stop_docker' => true])->removeWorktree();

            Process::assertRan(fn (PendingProcess $p) => $p->command === ['docker', 'compose', 'down', '-v']);
            File::delete("{$this->worktree}/{$file}");
            Run::query()->delete();
            Repository::query()->delete();
        }
    }

    public function test_compose_stays_up_when_the_repository_has_not_opted_in(): void
    {
        File::put("{$this->worktree}/compose.yaml", 'services: {}');

        $this->makeRun()->removeWorktree();

        Process::assertDidntRun(fn (PendingProcess $p) => ($p->command[0] ?? null) === 'docker');
        $this->assertTrue($this->git->ran('remove'));
    }

    public function test_compose_is_skipped_when_the_worktree_has_no_compose_file(): void
    {
        $this->makeRun(['stop_docker' => true])->removeWorktree();

        Process::assertDidntRun(fn (PendingProcess $p) => ($p->command[0] ?? null) === 'docker');
        $this->assertTrue($this->git->ran('remove'));
    }

    public function test_nothing_runs_when_the_worktree_is_kept(): void
    {
        File::put("{$this->worktree}/compose.yaml", 'services: {}');

        $this->makeRun(['stop_docker' => true, 'cleanup_worktrees' => false])->removeWorktree();

        Process::assertNothingRan();
        $this->assertFalse($this->git->ran('remove'));
    }

    public function test_a_failed_compose_down_still_removes_the_worktree(): void
    {
        $this->fakeProcesses(dockerExitCode: 1);
        File::put("{$this->worktree}/compose.yaml", 'services: {}');
        $run = $this->makeRun(['stop_docker' => true]);

        $run->removeWorktree();

        $this->assertTrue($this->git->ran('remove'));
        $this->assertStringContainsString('daemon not running', File::get($run->logPath()));
    }

    public function test_a_compose_down_that_times_out_still_removes_the_worktree(): void
    {
        Process::fake(function (PendingProcess $process) {
            if ($process->command[0] === 'docker') {
                throw new ProcessTimedOutException(
                    new SymfonyTimeoutException(new SymfonyProcess($process->command), SymfonyTimeoutException::TYPE_GENERAL),
                    Process::result(),
                );
            }

            return Process::result();
        });
        File::put("{$this->worktree}/compose.yaml", 'services: {}');
        $run = $this->makeRun(['stop_docker' => true]);

        $run->removeWorktree();

        $this->assertTrue($this->git->ran('remove'));
        $this->assertStringContainsString('docker compose down timed out', File::get($run->logPath()));
    }

    public function test_the_setting_can_be_turned_on_in_the_repository_settings(): void
    {
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'base_branch' => 'main', 'ready_label' => 'ready-for-agent', 'working_label' => 'agent-working',
            'human_label' => 'needs-human', 'model' => 'sonnet', 'concurrency' => 1, 'poll_interval_minutes' => 10,
            'max_turns' => 40, 'max_minutes' => 45, 'cleanup_worktrees' => true, 'watch_prs' => true,
        ]);
        $this->assertFalse($repo->fresh()->stop_docker);

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('stop_docker', true)
            ->call('save', Mockery::mock(GitHub::class)->shouldIgnoreMissing())
            ->assertHasNoErrors();

        $this->assertTrue($repo->fresh()->stop_docker);
    }

    public function test_the_script_prompts_mention_compose_and_sail(): void
    {
        $form = Livewire::test(RepositoryForm::class)->instance();

        foreach ([$form->setupScriptPrompt(), $form->verifyScriptPrompt()] as $prompt) {
            $this->assertStringContainsString('docker-compose.yml', $prompt);
            $this->assertStringContainsString('compose.yaml', $prompt);
            $this->assertStringContainsString('Sail', $prompt);
            $this->assertStringNotContainsString('{{', $prompt);
        }

        $this->assertStringContainsString('docker compose up -d --wait', $form->setupScriptPrompt());
        $this->assertStringContainsString('Bash(docker compose:*)', $form->setupScriptPrompt());
        $this->assertStringContainsString('docker compose exec -T', $form->verifyScriptPrompt());
    }

    public function test_the_docs_cover_docker_and_the_security_gap(): void
    {
        $settings = file_get_contents(base_path('docs/repository-settings.md'));

        $this->assertStringContainsString('docker compose up -d --wait', $settings);
        $this->assertStringContainsString('Bash(docker compose:*)', $settings);
        $this->assertStringContainsString('./vendor/bin/sail up -d --wait', $settings);
        $this->assertStringContainsString('Bash(./vendor/bin/sail:*)', $settings);
        $this->assertStringContainsString('Docker daemon', file_get_contents(base_path('docs/security.md')));
    }
}
