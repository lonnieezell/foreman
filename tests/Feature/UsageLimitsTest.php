<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Models\Repository;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class UsageLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['factory.claude_profiles' => ['work' => '/home/me/.claude-work']]);
        $this->freezeTime();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => true,
            'claude_profile' => 'work',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    /** A rate_limit_info payload as Claude's stream-json reports it. */
    private function limits(float $fiveHour, float $week, int $weekResetsIn = 3 * 86400, string $status = 'allowed'): array
    {
        return [
            'status' => $status,
            'resetsAt' => now()->timestamp + 3600,
            'rateLimitType' => 'five_hour',
            'unifiedWindows' => [
                'five_hour' => ['utilization' => $fiveHour, 'resetsAt' => now()->timestamp + 3600, 'status' => $status],
                'seven_day' => ['utilization' => $week, 'resetsAt' => now()->timestamp + $weekResetsIn, 'status' => 'allowed'],
            ],
        ];
    }

    private function holdReason(array $limits): ?string
    {
        app(Usage::class)->record('work', $limits);

        return app(Usage::class)->holdReason('work');
    }

    /** Runs the job against a faked `claude` that prints the given stream-json lines. */
    private function processRun(array $events): void
    {
        $run = $this->repository()->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            'resume_session_id' => 'abc', 'worktree_path' => sys_get_temp_dir(),
        ]);

        Process::fake(['*' => Process::result(implode("\n", array_map('json_encode', $events)))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        File::delete([$run->logPath(), $run->rawLogPath()]);
    }

    /** A GitHub fake with one ready issue that expects $claims claims. */
    private function github(int $claims): void
    {
        Queue::fake();
        $github = Mockery::mock(GitHub::class);
        $github->allows('readyIssues')->andReturn([['number' => 1, 'title' => 'Fix', 'url' => 'https://example.test/1']]);
        $github->expects('claim')->times($claims);
        $this->app->instance(GitHub::class, $github);
    }

    private function fakeProbe(array $limits): void
    {
        Process::fake(['*' => Process::result(json_encode(['type' => 'rate_limit_event', 'rate_limit_info' => $limits]))]);
    }

    public function test_rate_limit_events_from_a_run_are_recorded(): void
    {
        $this->processRun([
            ['type' => 'rate_limit_event', 'rate_limit_info' => $this->limits(0.42, 0.3)],
            ['type' => 'result', 'num_turns' => 1],
        ]);

        $this->assertSame(0.42, app(Usage::class)->snapshot('work')['windows']['five_hour']['utilization']);
    }

    public function test_a_usage_limit_error_without_an_event_holds_only_that_profile(): void
    {
        $this->processRun([['type' => 'result', 'is_error' => true, 'subtype' => 'error', 'result' => 'Claude AI usage limit reached']]);

        $this->assertNotNull(app(Usage::class)->holdReason('work'));
        $this->assertNull(app(Usage::class)->holdReason('default'));
        $this->assertFalse(Setting::paused());
    }

    public function test_no_snapshot_means_no_hold(): void
    {
        $this->assertNull(app(Usage::class)->holdReason('work'));
    }

    public function test_five_hour_window_holds_at_the_ceiling(): void
    {
        $this->assertNull($this->holdReason($this->limits(0.79, 0.1)));
        $this->assertStringContainsString('5-hour usage at 80%', $this->holdReason($this->limits(0.80, 0.1)));
    }

    public function test_a_window_that_has_reset_no_longer_holds(): void
    {
        app(Usage::class)->record('work', $this->limits(0.95, 0.1));
        $this->travel(61)->minutes();

        $this->assertNull(app(Usage::class)->holdReason('work'));
    }

    public function test_weekly_usage_ahead_of_pace_holds(): void
    {
        // One day into the week: 14% elapsed, so up to 24% is on pace
        $this->assertNull($this->holdReason($this->limits(0.1, 0.24, weekResetsIn: 6 * 86400)));
        $this->assertStringContainsString('ahead of', $this->holdReason($this->limits(0.1, 0.30, weekResetsIn: 6 * 86400)));
    }

    public function test_weekly_usage_holds_at_the_cap_even_late_in_the_week(): void
    {
        $this->assertNull($this->holdReason($this->limits(0.1, 0.89, weekResetsIn: 3600)));
        $this->assertStringContainsString('Weekly usage at 90%', $this->holdReason($this->limits(0.1, 0.90, weekResetsIn: 3600)));
    }

    public function test_a_rejected_window_holds_regardless_of_utilization(): void
    {
        $this->assertNotNull($this->holdReason($this->limits(0.1, 0.1, status: 'rejected')));
    }

    public function test_thresholds_come_from_config(): void
    {
        config(['factory.usage.five_hour_ceiling' => 0.5]);

        $this->assertNotNull($this->holdReason($this->limits(0.6, 0.1)));
    }

    public function test_poll_probes_a_stale_profile_before_claiming(): void
    {
        $this->repository();
        $this->github(claims: 1);
        $this->fakeProbe($this->limits(0.1, 0.1));

        $this->artisan('factory:poll')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'claude'
            && in_array('haiku', $process->command, true)
            && $process->environment === ['CLAUDE_CONFIG_DIR' => '/home/me/.claude-work']);
        $this->assertSame(0.1, app(Usage::class)->snapshot('work')['windows']['five_hour']['utilization']);
    }

    public function test_poll_uses_a_fresh_snapshot_without_probing(): void
    {
        $this->repository();
        $this->github(claims: 1);
        Process::fake();
        app(Usage::class)->record('work', $this->limits(0.1, 0.1));
        $this->travel(14)->minutes();

        $this->artisan('factory:poll')->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_poll_does_not_probe_when_there_is_no_work(): void
    {
        $this->repository();
        $github = Mockery::mock(GitHub::class);
        $github->allows('readyIssues')->andReturn([]);
        $this->app->instance(GitHub::class, $github);
        Process::fake();

        $this->artisan('factory:poll')->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_poll_claims_nothing_on_a_held_profile(): void
    {
        $this->repository();
        $this->github(claims: 0);
        $this->fakeProbe($this->limits(0.85, 0.1));

        $this->artisan('factory:poll')->assertSuccessful();
    }

    public function test_a_failed_probe_does_not_stop_the_factory(): void
    {
        $this->repository();
        $this->github(claims: 1);
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $this->artisan('factory:poll')->assertSuccessful();
    }

    public function test_run_anyway_lets_one_poll_through(): void
    {
        $this->repository();
        $this->github(claims: 1);
        Process::fake();
        app(Usage::class)->record('work', $this->limits(0.85, 0.1));

        Livewire::test(Dashboard::class)
            ->call('runAnyway', 'work')
            ->assertSet('flash', 'Next check will start runs on work regardless of usage.');

        $this->artisan('factory:poll', ['--force' => true])->assertSuccessful();
        $this->artisan('factory:poll', ['--force' => true])->assertSuccessful();   // held again: no second claim

        // The dashboard runs its own preflight processes, so only look for a probe
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('haiku', $process->command, true));
    }

    public function test_dashboard_shows_usage_and_holds(): void
    {
        $this->repository();
        $this->repository(['name' => 'other', 'github_repo' => 'owner/other', 'claude_profile' => null]);
        app(Usage::class)->record('work', $this->limits(0.85, 0.53));

        $this->get('/')
            ->assertSeeInOrder(['work', 'Held', '5h', '85%', 'week', '53%'])
            ->assertSee('style="width: 85%"', false)
            ->assertSee('Holding new runs on the work profile.')
            ->assertSee('Run anyway')
            ->assertSee('usage unknown');   // the default profile has no snapshot yet
    }

    public function test_dashboard_shows_every_configured_profile(): void
    {
        config(['factory.claude_profiles' => ['work' => '/home/me/.claude-work', 'spare' => '/home/me/.claude-spare']]);
        $this->repository();

        $this->get('/')
            ->assertSee('aria-label="Claude usage for the spare profile"', false)   // no repository uses it and it spent nothing today
            ->assertSeeInOrder(['spare', '5h', '—', 'week', '—', 'usage unknown']);
    }

    public function test_gauge_marks_each_window_against_its_limit(): void
    {
        // Three days into the week: 57% elapsed + 10% margin = 67% pace
        app(Usage::class)->record('work', $this->limits(0.42, 0.62, weekResetsIn: 3 * 86400));

        $gauge = app(Usage::class)->gauge('work');

        $this->assertSame(['percent' => 42, 'limit' => 80, 'tone' => 'good'], array_intersect_key($gauge['five_hour'], array_flip(['percent', 'limit', 'tone'])));
        $this->assertSame(['percent' => 62, 'limit' => 67, 'tone' => 'attention'], array_intersect_key($gauge['seven_day'], array_flip(['percent', 'limit', 'tone'])));
        $this->assertStringContainsString('pace 67%', $gauge['seven_day']['note']);
        $this->assertFalse($gauge['held']);
    }

    public function test_gauge_shows_a_window_over_its_limit_as_held(): void
    {
        app(Usage::class)->record('work', $this->limits(0.86, 0.1));

        $gauge = app(Usage::class)->gauge('work');

        $this->assertSame('bad', $gauge['five_hour']['tone']);
        $this->assertTrue($gauge['held']);
        $this->assertNull(app(Usage::class)->gauge('default'));
    }

    public function test_gauge_shows_a_reset_window_as_empty(): void
    {
        app(Usage::class)->record('work', $this->limits(0.95, 0.1));
        $this->travel(61)->minutes();

        $gauge = app(Usage::class)->gauge('work');

        $this->assertSame(0, $gauge['five_hour']['percent']);
        $this->assertSame('reset', $gauge['five_hour']['note']);
    }
}
