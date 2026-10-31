<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\PrWatcher;
use App\Services\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PrWatchTest extends TestCase
{
    use RefreshDatabase;

    private const PR = 'https://github.com/owner/app/pull/12';

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->worktree = sys_get_temp_dir().'/foreman-pr-watch-'.uniqid();
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
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => true,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    private function watchedRun(array $attributes = [], array $repository = []): Run
    {
        return $this->repository($repository)->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => RunStatus::WatchingPr, 'pr_url' => self::PR, 'head_sha' => 'aaaaaaa1111',
            'session_id' => 'sess-1', 'worktree_path' => $this->worktree,
            'watch_started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(10),
            ...$attributes,
        ]);
    }

    /** The shape GitHub::pullRequest returns. */
    private function pr(array $overrides = []): array
    {
        return [
            'state' => 'OPEN',
            'head_sha' => 'aaaaaaa1111',
            'checks' => [['name' => 'tests', 'state' => 'passed', 'url' => null]],
            'threads' => [],
            'bot_activity_at' => null,
            ...$overrides,
        ];
    }

    private function thread(string $id): array
    {
        return ['id' => $id, 'path' => 'app/Foo.php', 'line' => 3, 'author' => 'coderabbitai', 'body' => 'Null check missing.', 'url' => "https://example.test/{$id}"];
    }

    private function github(array $pr): MockInterface
    {
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn($pr);
        $this->app->instance(GitHub::class, $github);

        return $github;
    }

    private function check(Run $run): Run
    {
        Queue::fake();
        Process::fake();
        $usage = Mockery::mock(Usage::class);
        $usage->shouldReceive('allows')->andReturn(true);
        $this->app->instance(Usage::class, $usage);

        app(PrWatcher::class)->check($run);

        return $run->fresh();
    }

    public function test_pull_request_reads_checks_bot_threads_and_bot_activity(): void
    {
        $bot = ['__typename' => 'Bot', 'login' => 'coderabbitai'];
        $human = ['__typename' => 'User', 'login' => 'lonnie'];
        $thread = fn (string $id, bool $resolved, array $author) => ['id' => $id, 'isResolved' => $resolved, 'path' => 'a.php', 'line' => 1,
            'comments' => ['nodes' => [['author' => $author, 'body' => 'b', 'url' => "u/{$id}"]]]];

        Process::fake(['*' => Process::result(json_encode(['data' => ['repository' => ['pullRequest' => [
            'state' => 'OPEN',
            'headRefOid' => 'abc123',
            'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['contexts' => ['nodes' => [
                ['__typename' => 'CheckRun', 'name' => 'tests', 'status' => 'COMPLETED', 'conclusion' => 'FAILURE', 'detailsUrl' => 'd'],
                ['__typename' => 'CheckRun', 'name' => 'lint', 'status' => 'IN_PROGRESS', 'conclusion' => null, 'detailsUrl' => null],
                ['__typename' => 'StatusContext', 'context' => 'CodeRabbit', 'state' => 'SUCCESS', 'targetUrl' => null],
            ]]]]]]],
            'reviewThreads' => ['nodes' => [$thread('T1', false, $bot), $thread('T2', true, $bot), $thread('T3', false, $human)]],
            'comments' => ['nodes' => [['author' => $bot, 'updatedAt' => '2026-10-31T10:05:00Z'], ['author' => $human, 'updatedAt' => '2026-10-31T11:00:00Z']]],
            'reviews' => ['nodes' => [['author' => $bot, 'submittedAt' => '2026-10-31T10:07:00Z']]],
        ]]]]))]);

        $pr = app(GitHub::class)->pullRequest($this->repository(), self::PR);

        $this->assertSame('abc123', $pr['head_sha']);
        $this->assertSame(['failed', 'pending', 'passed'], array_column($pr['checks'], 'state'));
        $this->assertSame(['T1'], array_column($pr['threads'], 'id'));
        $this->assertTrue(Carbon::parse('2026-10-31T10:07:00Z')->equalTo($pr['bot_activity_at']));
        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'api', 'graphql']
            && in_array('number=12', $p->command, true)
            && ! in_array('--repo', $p->command, true));
    }

    public function test_waits_while_a_check_is_pending(): void
    {
        $this->github($this->pr(['checks' => [['name' => 'tests', 'state' => 'pending', 'url' => null]]]));

        $this->assertSame(RunStatus::WatchingPr, $this->check($this->watchedRun())->status);
    }

    public function test_waits_for_bots_to_go_quiet(): void
    {
        $this->github($this->pr(['threads' => [$this->thread('T1')], 'bot_activity_at' => now()->subMinutes(4)]));

        $this->assertSame(RunStatus::WatchingPr, $this->check($this->watchedRun())->status);
        Queue::assertNothingPushed();
    }

    public function test_waits_out_the_quiet_period_right_after_a_push(): void
    {
        // A bot reviewed before this push, so it may review again
        $this->github($this->pr(['bot_activity_at' => now()->subMinutes(20)]));

        $this->assertSame(RunStatus::WatchingPr, $this->check($this->watchedRun(['watch_started_at' => now()->subMinutes(2)]))->status);
    }

    public function test_is_ready_after_a_minute_when_no_bot_has_posted(): void
    {
        $this->github($this->pr(['checks' => []]));

        $this->assertSame(RunStatus::PrReady, $this->check($this->watchedRun(['watch_started_at' => now()->subMinute()]))->status);
    }

    public function test_waits_the_first_minute_even_without_bots(): void
    {
        $this->github($this->pr(['checks' => []]));

        $this->assertSame(RunStatus::WatchingPr, $this->check($this->watchedRun(['watch_started_at' => now()->subSeconds(30)]))->status);
    }

    public function test_green_with_no_bot_threads_is_ready(): void
    {
        $this->github($this->pr());

        $run = $this->check($this->watchedRun());

        $this->assertSame(RunStatus::PrReady, $run->status);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'worktree', 'remove', '--force', $this->worktree]);
    }

    public function test_failing_checks_and_bot_threads_start_a_round(): void
    {
        $failing = ['name' => 'tests', 'state' => 'failed', 'url' => 'https://ci.test/1'];
        $this->github($this->pr(['checks' => [$failing], 'threads' => [$this->thread('T1')], 'bot_activity_at' => now()->subMinutes(6)]));

        $run = $this->check($this->watchedRun());
        $round = Run::latest('id')->first();

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame(RunStatus::Queued, $round->status);
        $this->assertSame(1, $round->pr_round);
        $this->assertSame($run->id, $round->previous_run_id);
        $this->assertSame('sess-1', $round->resume_session_id);
        $this->assertSame('aaaaaaa1111', $round->head_sha);
        $this->assertSame([$failing], $round->pr_feedback['checks']);
        $this->assertSame('T1', $round->pr_feedback['threads'][0]['id']);
        Queue::assertPushedOn('runs', ProcessRun::class);
    }

    public function test_timeout_starts_a_round_even_with_checks_pending(): void
    {
        $this->github($this->pr(['checks' => [['name' => 'slow', 'state' => 'pending', 'url' => null]]]));

        $this->check($this->watchedRun(['watch_started_at' => now()->subMinutes(31)]));

        $this->assertSame('pending', Run::latest('id')->first()->pr_feedback['checks'][0]['state']);
    }

    public function test_after_the_last_round_it_hands_the_pr_to_a_human(): void
    {
        $github = $this->github($this->pr(['threads' => [$this->thread('T1')]]));
        $github->expects('commentOnPullRequest')->withArgs(fn ($repo, $url, $body) => $url === self::PR && str_contains($body, 'https://example.test/T1'));
        $github->expects('addHumanLabel')->withArgs(fn ($repo, $issue) => $issue === 7);

        $run = $this->check($this->watchedRun(['pr_round' => 2]));

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame(1, Run::count());
    }

    public function test_merged_closed_and_foreign_pushes_stop_watching(): void
    {
        $this->github($this->pr(['state' => 'MERGED']));
        $this->assertSame(RunStatus::PrReady, $this->check($this->watchedRun())->status);

        $this->github($this->pr(['state' => 'CLOSED']));
        $this->assertSame(RunStatus::Cancelled, $this->check($this->watchedRun())->status);

        $this->github($this->pr(['head_sha' => 'bbbbbbb2222']));
        $this->assertSame(RunStatus::NeedsHuman, $this->check($this->watchedRun())->status);
    }

    public function test_a_paused_foreman_holds_the_round_but_keeps_watching(): void
    {
        Setting::put('paused', 1);
        $this->github($this->pr(['threads' => [$this->thread('T1')]]));

        $this->assertSame(RunStatus::WatchingPr, $this->check($this->watchedRun())->status);
        Queue::assertNothingPushed();
    }

    public function test_poll_watches_prs_while_paused(): void
    {
        Setting::put('paused', 1);
        $run = $this->watchedRun();
        $this->github($this->pr());
        Process::fake();

        $this->artisan('foreman:poll')->assertSuccessful();

        $this->assertSame(RunStatus::PrReady, $run->fresh()->status);
    }

    /** Runs ProcessRun with a fake `claude`; $onClaude runs when the agent would. */
    private function process(Run $run, MockInterface $github, ?\Closure $onClaude = null): void
    {
        Process::fake(function (PendingProcess $process) use ($onClaude) {
            if ($process->command[0] === 'claude') {
                $onClaude && $onClaude();

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 3]));
            }

            return $process->command === ['git', 'rev-parse', '--git-path', 'info/exclude']
                ? Process::result($this->worktree.'/exclude')
                : Process::result();
        });

        (new ProcessRun($run))->handle($github);
    }

    public function test_an_opened_pr_is_watched_and_its_worktree_kept(): void
    {
        $run = $this->watchedRun(['status' => RunStatus::Queued, 'pr_url' => null, 'head_sha' => null, 'resume_session_id' => 'sess-0']);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn(self::PR);
        $github->allows('pullRequest')->andReturn($this->pr(['head_sha' => 'ccccccc3333']));

        $this->process($run, $github);
        $run->refresh();

        $this->assertSame(RunStatus::WatchingPr, $run->status);
        $this->assertSame('ccccccc3333', $run->head_sha);
        $this->assertSame(now()->toDateTimeString(), $run->watch_started_at->toDateTimeString());
        Process::assertDidntRun(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['git', 'worktree', 'remove']);
    }

    public function test_without_watching_an_opened_pr_ends_the_run(): void
    {
        $run = $this->watchedRun(['status' => RunStatus::Queued, 'pr_url' => null, 'resume_session_id' => 'sess-0'], ['watch_prs' => false]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn(self::PR);

        $this->process($run, $github);

        $this->assertSame(RunStatus::PrOpened, $run->fresh()->status);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'worktree', 'remove', '--force', $this->worktree]);
    }

    public function test_a_round_posts_verdicts_and_watches_the_new_head(): void
    {
        $run = $this->watchedRun([
            'status' => RunStatus::Queued, 'pr_round' => 1, 'resume_session_id' => 'sess-1',
            'pr_feedback' => ['checks' => [], 'threads' => array_map(fn ($id) => $this->thread($id), ['T1', 'T2', 'T3', 'T4'])],
        ]);
        $github = Mockery::mock(GitHub::class);
        $github->allows('release');
        $github->allows('pullRequest')->andReturn($this->pr(['head_sha' => 'ddddddd4444']));
        $github->expects('replyToThread')->with(Mockery::any(), 'T1', 'Fixed in ddddddd: Added the null check.');
        $github->expects('resolveThread')->with(Mockery::any(), 'T1');
        $github->expects('replyToThread')->with(Mockery::any(), 'T2', 'Not changing: Guarded on line 2.');
        $github->expects('resolveThread')->with(Mockery::any(), 'T2');
        $github->expects('replyToThread')->with(Mockery::any(), 'T3', 'Leaving for a human: Which format?');
        // T4 has no verdict: nothing posted, left open

        $this->process($run, $github, function () {
            File::ensureDirectoryExists($this->worktree.'/.foreman');
            File::put($this->worktree.'/.foreman/review.json', json_encode([
                ['thread_id' => 'T1', 'verdict' => 'fixed', 'reply' => 'Added the null check.'],
                ['thread_id' => 'T2', 'verdict' => 'declined', 'reply' => 'Guarded on line 2.'],
                ['thread_id' => 'T3', 'verdict' => 'unsure', 'reply' => 'Which format?'],
                ['thread_id' => 'T9', 'verdict' => 'fixed', 'reply' => 'Not a thread we sent.'],
            ]));
        });
        $run->refresh();

        $this->assertSame(RunStatus::WatchingPr, $run->status);
        $this->assertSame('ddddddd4444', $run->head_sha);
        $this->assertStringContainsString(".foreman/\n", File::get($this->worktree.'/exclude'));
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'claude'
            && str_contains($p->command[2], 'round 1 of 2')
            && str_contains($p->command[2], '### T1')
            && str_contains($p->command[array_search('--allowedTools', $p->command) + 1], 'Bash(gh run view:*)')
            && in_array('--resume', $p->command, true));
    }

    public function test_fixed_without_a_push_posts_nothing(): void
    {
        $run = $this->watchedRun([
            'status' => RunStatus::Queued, 'pr_round' => 1, 'resume_session_id' => 'sess-1',
            'pr_feedback' => ['checks' => [], 'threads' => [$this->thread('T1')]],
        ]);
        File::ensureDirectoryExists($this->worktree.'/.foreman');
        $github = Mockery::mock(GitHub::class);
        $github->allows('release');
        $github->allows('pullRequest')->andReturn($this->pr());   // head unchanged
        $github->expects('replyToThread')->never();
        $github->expects('resolveThread')->never();

        $this->process($run, $github, fn () => File::put($this->worktree.'/.foreman/review.json',
            json_encode([['thread_id' => 'T1', 'verdict' => 'fixed', 'reply' => 'Done.']])));

        $this->assertSame(RunStatus::WatchingPr, $run->fresh()->status);
    }

    public function test_resuming_without_a_worktree_rebuilds_from_the_pushed_branch(): void
    {
        $missing = $this->worktree.'/gone';
        $run = $this->watchedRun(['status' => RunStatus::Queued, 'pr_url' => null, 'resume_session_id' => 'sess-0', 'worktree_path' => $missing]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);

        try {
            $this->process($run, $github);
        } finally {
            Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, 4) === ['git', 'worktree', 'add', '-B']
                && end($p->command) === 'origin/agent/issue-7');
        }
    }

    public function test_stopping_a_watched_run_leaves_the_pr_alone(): void
    {
        $run = $this->watchedRun();
        $github = Mockery::mock(GitHub::class);
        $github->expects('commentOnPullRequest')->never();
        $this->app->instance(GitHub::class, $github);
        Process::fake();

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSee('Stop watching')
            ->call('cancel');

        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_a_pr_handed_to_a_human_offers_no_continue_or_start_over(): void
    {
        $run = $this->watchedRun(['status' => RunStatus::NeedsHuman, 'pr_round' => 2]);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSee('Needs you · round 2/2')
            ->assertDontSee('Continue after my reply')
            ->assertDontSee('Start over');
    }

    public function test_the_repository_form_saves_the_watch_setting(): void
    {
        $repo = $this->repository();
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $this->app->instance(GitHub::class, $github);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('watch_prs', true)
            ->set('watch_prs', false)
            ->call('save');

        $this->assertFalse($repo->fresh()->watch_prs);
    }
}
