<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use App\Services\PrWatcher;
use App\Services\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class EffortTest extends TestCase
{
    use RefreshDatabase;

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    private function queuedRun(Repository $repo, array $attributes = []): Run
    {
        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => $repo->fresh()->model,
            'worktree_path' => sys_get_temp_dir(),
            ...$attributes,
        ]);
    }

    /** Runs the job against a faked `claude`, returning the command it used. */
    private function claudeCommand(Run $run): array
    {
        Process::fake(['*' => Process::result(json_encode(['type' => 'result', 'num_turns' => 1]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();

        (new ProcessRun($run))->handle($github);

        $command = null;
        Process::assertRan(function (PendingProcess $process) use (&$command) {
            if ($process->command[0] === 'claude') {
                $command = $process->command;
            }

            return $command !== null;
        });
        $log = File::get($run->logPath());
        File::delete([$run->logPath(), $run->rawLogPath()]);

        return [$command, $log];
    }

    public function test_dashboard_saves_model_and_effort(): void
    {
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->call('saveClaudeSettings', $repo->id, 'opus', 'xhigh')
            ->assertReturned(null);

        $this->assertSame('opus', $repo->fresh()->model);
        $this->assertSame('xhigh', $repo->fresh()->effort);
    }

    public function test_dashboard_saves_default_effort_as_null(): void
    {
        $repo = $this->repository(['effort' => 'high']);

        Livewire::test(Dashboard::class)->call('saveClaudeSettings', $repo->id, 'fable', '');

        $this->assertSame('fable', $repo->fresh()->model);
        $this->assertNull($repo->fresh()->effort);
    }

    public function test_dashboard_rejects_unknown_model_and_effort(): void
    {
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->call('saveClaudeSettings', $repo->id, 'gpt', 'low')
            ->assertReturned(fn ($error) => str_contains($error, 'model'))
            ->call('saveClaudeSettings', $repo->id, 'opus', 'extreme')
            ->assertReturned(fn ($error) => str_contains($error, 'effort'));

        $this->assertSame('sonnet', $repo->fresh()->model);
        $this->assertNull($repo->fresh()->effort);
    }

    public function test_dashboard_save_for_a_missing_repository_fails(): void
    {
        Process::fake();

        Livewire::test(Dashboard::class)
            ->call('saveClaudeSettings', 999, 'opus', 'low')
            ->assertNotFound();
    }

    public function test_dashboard_trigger_shows_model_and_effort(): void
    {
        Process::fake();
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('title="owner/app · sonnet · default"')
            ->assertSeeHtml('data-model="sonnet"')
            ->assertSeeHtml('data-effort=""');

        $repo->update(['effort' => 'high']);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('title="owner/app · sonnet high · default"')
            ->assertSeeHtml('data-effort="high"')
            ->assertSee('sonnet high');
    }

    public function test_settings_form_saves_and_validates_effort_and_model(): void
    {
        $repo = $this->repository();
        $this->app->instance(GitHub::class, Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('effort', '')
            ->assertSee('Fable')
            ->assertSee('XHigh')
            ->set('effort', 'extreme')
            ->call('save')
            ->assertHasErrors('effort')
            ->set('effort', '')
            ->set('model', 'gpt')
            ->call('save')
            ->assertHasErrors('model')
            ->set('model', 'fable')
            ->set('effort', 'max')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('fable', $repo->fresh()->model);
        $this->assertSame('max', $repo->fresh()->effort);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('effort', 'max')
            ->set('effort', '')
            ->call('save');

        $this->assertNull($repo->fresh()->effort);
    }

    public function test_a_polled_run_copies_the_repository_effort(): void
    {
        $repo = $this->repository(['enabled' => true, 'effort' => 'low']);
        Queue::fake();
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('readyIssues')->andReturn([['number' => 1, 'title' => 'Fix', 'url' => 'https://example.test/1']]);
        $this->app->instance(GitHub::class, $github);
        $usage = Mockery::mock(Usage::class)->shouldIgnoreMissing();
        $usage->shouldReceive('allows')->andReturn(true);
        $this->app->instance(Usage::class, $usage);

        $this->artisan('foreman:poll')->assertSuccessful();

        $this->assertSame('low', $repo->runs()->first()->effort);
    }

    public function test_a_pr_round_copies_the_repository_effort(): void
    {
        $repo = $this->repository(['effort' => 'medium']);
        $run = $this->queuedRun($repo, ['status' => RunStatus::WatchingPr, 'session_id' => 's', 'pr_url' => 'https://example.test/pr/1', 'head_sha' => 'abc']);
        Queue::fake();

        (new \ReflectionMethod(PrWatcher::class, 'startRound'))
            ->invoke(app(PrWatcher::class), $run, [], []);

        $this->assertSame('medium', Run::latest('id')->first()->effort);
    }

    public function test_continuing_after_a_reply_copies_the_repository_effort(): void
    {
        $repo = $this->repository(['effort' => 'max']);
        $run = $this->queuedRun($repo, ['status' => RunStatus::NeedsHuman, 'session_id' => 's']);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        Queue::fake();

        $next = $run->continueAfterReply($github);

        $this->assertSame('max', $next->effort);
    }

    public function test_process_run_leaves_out_effort_when_it_is_default(): void
    {
        [$command, $log] = $this->claudeCommand($this->queuedRun($this->repository()));

        $this->assertNotContains('--effort', $command);
        $this->assertStringContainsString('Starting Claude (sonnet, default profile', $log);
    }

    public function test_process_run_passes_effort_when_the_run_has_one(): void
    {
        [$command, $log] = $this->claudeCommand($this->queuedRun($this->repository(), ['effort' => 'high']));

        $this->assertSame('high', $command[array_search('--effort', $command) + 1]);
        $this->assertStringContainsString('Starting Claude (sonnet, high effort, default profile', $log);
    }

    public function test_process_run_uses_the_runs_model_and_effort_not_the_repositorys(): void
    {
        $repo = $this->repository(['model' => 'haiku', 'effort' => 'low']);
        $run = $this->queuedRun($repo, ['model' => 'opus', 'effort' => 'max']);

        [$command] = $this->claudeCommand($run);

        $this->assertSame('opus', $command[array_search('--model', $command) + 1]);
        $this->assertSame('max', $command[array_search('--effort', $command) + 1]);
    }

    public function test_run_detail_shows_effort(): void
    {
        Process::fake();
        $repo = $this->repository();

        $default = $this->queuedRun($repo);
        Livewire::test(RunDetail::class, ['run' => $default])->assertSeeHtml('<dt>Effort</dt><dd>Default</dd>');

        $set = $this->queuedRun($repo, ['effort' => 'xhigh']);
        Livewire::test(RunDetail::class, ['run' => $set])->assertSeeHtml('<dt>Effort</dt><dd>xhigh</dd>');
    }
}
