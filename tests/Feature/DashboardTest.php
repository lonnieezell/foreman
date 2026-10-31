<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function repository(bool $enabled): Repository
    {
        return Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => $enabled,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);
    }

    public function test_starting_a_repository_checks_it_straight_away(): void
    {
        $repo = $this->repository(enabled: false);
        $github = Mockery::mock(GitHub::class);
        $github->expects('readyIssues')->once()->andReturn([]);
        $this->app->instance(GitHub::class, $github);

        Livewire::test(Dashboard::class)
            ->call('toggleRepository', $repo->id)
            ->assertSet('flash', 'app started and checked for ready issues.');

        $this->assertTrue($repo->fresh()->enabled);
        $this->assertNotNull($repo->fresh()->last_polled_at);
    }

    public function test_stopping_a_repository_does_not_check_it(): void
    {
        $repo = $this->repository(enabled: true);
        $github = Mockery::mock(GitHub::class);
        $github->expects('readyIssues')->never();
        $this->app->instance(GitHub::class, $github);

        Livewire::test(Dashboard::class)
            ->call('toggleRepository', $repo->id)
            ->assertSet('flash', 'app stopped. Runs in progress will finish.');
    }

    public function test_a_stopped_repository_shows_only_its_heading_and_what_finished_today(): void
    {
        $repo = $this->repository(enabled: false);
        Process::fake();

        $this->get('/')
            ->assertSee('owner/app')
            ->assertDontSee('x-data="countdown"', false)
            ->assertDontSee('Writing code')
            ->assertDontSee('Done today')
            ->assertDontSee('$0.00 today');

        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => RunStatus::Failed,
        ]);

        $this->get('/')->assertSee('Done today')->assertDontSee('Writing code');

        $run->update(['status' => RunStatus::Working]);   // stopping lets runs in progress finish, so keep them on the line

        $this->get('/')->assertSee('Writing code');
    }

    public function test_a_running_repository_counts_down_to_its_next_check(): void
    {
        $this->freezeTime();
        $repo = $this->repository(enabled: true);
        $repo->update(['last_polled_at' => now()->subMinutes(3), 'poll_interval_minutes' => 10]);
        Process::fake();

        $this->get('/')
            ->assertSee('data-next="'.now()->addMinutes(7)->timestamp.'"', false)
            ->assertSee('x-data="countdown"', false);
    }

    public function test_a_repository_over_its_daily_limit_shows_a_notice_instead_of_its_line(): void
    {
        $repo = $this->repository(enabled: true);
        $repo->update(['daily_budget' => 5]);
        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => RunStatus::Failed, 'cost_usd' => 5.25,
        ]);
        Process::fake();

        $this->get('/')
            ->assertSee('Daily limit of $5.00 reached')
            ->assertDontSee('Writing code')
            ->assertDontSee('x-data="countdown"', false)
            ->assertSee('Done today');

        $run->update(['status' => RunStatus::Working]);   // runs already going still finish, so keep them on the line

        $this->get('/')->assertSee('Daily limit of $5.00 reached')->assertSee('Writing code');
    }

    public function test_pausing_shows_in_the_stats_row_without_a_banner(): void
    {
        Process::fake();

        Livewire::test(Dashboard::class)
            ->call('togglePause')
            ->assertSet('flash', null)
            ->assertSee('Paused')
            ->call('togglePause')
            ->assertSet('flash', null)
            ->assertSee('Running');
    }

    public function test_retrying_a_run_starts_it_straight_away(): void
    {
        $repo = $this->repository(enabled: true);
        $run = $repo->runs()->create([
            'issue_number' => 907, 'issue_title' => 'Fix', 'branch' => 'agent/issue-907', 'model' => 'sonnet',
            'status' => RunStatus::Cancelled,
        ]);
        $github = Mockery::mock(GitHub::class);
        $github->expects('requeue');
        $github->expects('readyIssues')->andReturn([['number' => 907, 'title' => 'Fix', 'url' => 'https://example.test/907']]);
        $github->expects('claim');
        $this->app->instance(GitHub::class, $github);
        Queue::fake();
        Process::fake();

        Livewire::test(Dashboard::class)
            ->call('retryRun', $run->id)
            ->assertSet('flash', '#907 is back in the queue for app and has started.');

        Queue::assertPushedOn('runs', ProcessRun::class);
    }

    public function test_retrying_from_the_run_page_opens_the_new_run(): void
    {
        $repo = $this->repository(enabled: true);
        $run = $repo->runs()->create(['issue_number' => 907, 'branch' => 'agent/issue-907', 'model' => 'sonnet', 'status' => RunStatus::Cancelled]);
        $github = Mockery::mock(GitHub::class);
        $github->expects('requeue');
        $github->expects('readyIssues')->andReturn([['number' => 907, 'title' => 'Fix', 'url' => 'https://example.test/907']]);
        $github->expects('claim');
        $this->app->instance(GitHub::class, $github);
        Queue::fake();
        Process::fake();

        Livewire::test(RunDetail::class, ['run' => $run])
            ->call('retry')
            ->assertRedirect(route('runs.show', $run->id + 1));
    }

    public function test_retrying_without_a_free_slot_leaves_it_queued(): void
    {
        $repo = $this->repository(enabled: true);
        $repo->runs()->create(['issue_number' => 1, 'branch' => 'agent/issue-1', 'model' => 'sonnet', 'status' => RunStatus::Working]);
        $run = $repo->runs()->create(['issue_number' => 907, 'branch' => 'agent/issue-907', 'model' => 'sonnet', 'status' => RunStatus::Failed]);
        $github = Mockery::mock(GitHub::class);
        $github->expects('requeue');
        $github->expects('readyIssues')->never();
        $this->app->instance(GitHub::class, $github);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->call('retry')
            ->assertSet('flash', '#907 is back in the queue. A new run will appear on the dashboard.');
    }

    public function test_failed_runs_show_their_error_in_recent_runs(): void
    {
        $this->repository(enabled: false)->runs()->create([
            'issue_number' => 64, 'issue_title' => 'Fix', 'branch' => 'agent/issue-64', 'model' => 'sonnet',
            'status' => RunStatus::Failed,
            'error' => 'Running setup script failed: cp: .env.example: No such file or directory',
        ]);

        $this->get('/')->assertSee('cp: .env.example: No such file or directory');
    }
}
