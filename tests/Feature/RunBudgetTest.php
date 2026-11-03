<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\Support\FakeClaude;
use Tests\TestCase;

class RunBudgetTest extends TestCase
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

    /** Runs the job against a fake `claude`, returning the budget it gave the agent. */
    private function agentBudget(Repository $repo): string|float|null
    {
        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => $repo->fresh()->model,
            'worktree_path' => sys_get_temp_dir(),
        ]);
        $claude = FakeClaude::install($this->app);
        Process::fake();
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        File::delete([$run->logPath(), $run->rawLogPath()]);

        return $claude->agentCalls[0]['budget'];
    }

    public function test_form_saves_the_run_cap_and_empty_clears_it(): void
    {
        $repo = $this->repository();
        $this->app->instance(GitHub::class, Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('max_budget_per_run', '')
            ->set('max_budget_per_run', 'lots')
            ->call('save')
            ->assertHasErrors('max_budget_per_run')
            ->set('max_budget_per_run', '-1')
            ->call('save')
            ->assertHasErrors('max_budget_per_run')
            ->set('max_budget_per_run', '2.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2.50', $repo->fresh()->max_budget_per_run);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('max_budget_per_run', '2.50')
            ->set('max_budget_per_run', '')
            ->call('save');

        $this->assertNull($repo->fresh()->max_budget_per_run);
    }

    public function test_runs_start_with_the_budget_when_a_cap_is_set(): void
    {
        $this->assertSame('2.50', $this->agentBudget($this->repository(['max_budget_per_run' => 2.5])));
    }

    public function test_runs_start_without_a_budget_when_no_cap_is_set(): void
    {
        $this->assertNull($this->agentBudget($this->repository()));
    }
}
