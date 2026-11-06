<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Models\RunMessage;
use App\Models\Setting;
use App\Services\AgentReport;
use App\Services\Claude\SessionNotFound;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\FakeClaude;
use Tests\Support\FakeWorktree;
use Tests\TestCase;

/** Fix conflicts: the button, the click-time re-check, and the conflict-fix run. */
class ConflictFixTest extends TestCase
{
    use RefreshDatabase;

    private const PR = 'https://github.com/owner/app/pull/12';

    private string $worktree;

    /** Unique, so the worktree a conflict fix rebuilds under storage/ is this test's own. */
    private string $githubRepo;

    private FakeWorktree $git;

    private FakeClaude $claude;

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = FakeWorktree::install($this->app);
        $this->claude = FakeClaude::install($this->app);
        $this->githubRepo = 'owner/conflict-fix-'.uniqid();
        $this->worktree = sys_get_temp_dir().'/foreman-conflict-fix-'.uniqid();
        File::ensureDirectoryExists($this->worktree);

        // The setup script is the only process left once git and claude are faked
        Process::fake(function (PendingProcess $process) {
            $this->git->calls[] = 'setup';

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        File::deleteDirectory(dirname(Repository::make(['github_repo' => $this->githubRepo])->worktreePath(7)));
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => $this->githubRepo, 'enabled' => true,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true', 'locked_paths' => 'config/locked.php',
            ...$attributes,
        ]);
    }

    /** A watched run the watcher stopped on a merge conflict. */
    private function conflicted(array $attributes = []): Run
    {
        return $this->repository()->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => RunStatus::NeedsHuman, 'stop_reason' => 'merge_conflict',
            'pr_url' => self::PR, 'pr_round' => 1, 'head_sha' => 'aaaaaaa1111', 'risk_tier' => 2,
            'session_id' => 'sess-1', 'worktree_path' => $this->worktree, 'finished_at' => now(),
            ...$attributes,
        ]);
    }

    private function github(array $pr = []): MockInterface
    {
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'CONFLICTING', 'head_sha' => 'aaaaaaa1111', ...$pr]);
        $this->app->instance(GitHub::class, $github);

        return $github;
    }

    public function test_the_button_shows_on_the_run_page_and_the_dashboard_for_a_merge_conflict(): void
    {
        $run = $this->conflicted();

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSeeHtml('wire:click="fixConflicts"')
            ->assertSee('The PR conflicts with main');

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('wire:click="fixConflicts('.$run->id.')"')
            ->assertSee('Resume the agent to merge main and resolve the conflicts? This spends tokens.');
    }

    public function test_the_button_shows_only_for_a_merge_conflict_on_the_issues_newest_run(): void
    {
        $other = $this->conflicted(['stop_reason' => 'rounds_ran_out']);
        Livewire::test(RunDetail::class, ['run' => $other])->assertDontSeeHtml('wire:click="fixConflicts"');

        $older = $this->conflicted();
        $older->repository->runs()->create(['issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued]);

        Livewire::test(RunDetail::class, ['run' => $older])->assertDontSeeHtml('wire:click="fixConflicts"');
    }

    public function test_a_click_starts_a_conflict_fix_at_once_even_while_paused_or_over_budget(): void
    {
        Setting::put('paused', 1);
        Setting::put('global_daily_budget', 0.01);
        $run = $this->conflicted(['cost_usd' => 5]);
        $github = $this->github();
        $github->expects('reclaim')->once()->withArgs(fn ($repo, $issue) => $issue === 7);
        Queue::fake();

        Livewire::test(RunDetail::class, ['run' => $run])->call('fixConflicts')->assertRedirect();

        $fix = Run::latest('id')->first();
        $this->assertSame(Run::CONFLICT_FIX, $fix->kind);
        $this->assertSame(RunStatus::Queued, $fix->status);
        $this->assertSame(1, $fix->pr_round, 'keeps the round count');
        $this->assertSame($run->id, $fix->previous_run_id);
        $this->assertSame('sess-1', $fix->resume_session_id);
        $this->assertSame(self::PR, $fix->pr_url);
        $this->assertSame('aaaaaaa1111', $fix->head_sha);
        $this->assertSame(RunStatus::NeedsHuman, $run->fresh()->status, 'the parent stays as it was');
        $this->assertSame('merge_conflict', $run->fresh()->stop_reason);
        Queue::assertPushedOn('runs', ProcessRun::class);
    }

    public function test_a_double_click_starts_one_run(): void
    {
        $run = $this->conflicted();
        $this->github();
        Queue::fake();

        Livewire::test(Dashboard::class)->call('fixConflicts', $run->id)->call('fixConflicts', $run->id);

        $this->assertSame(2, Run::count());
        Queue::assertPushed(ProcessRun::class, 1);
    }

    public function test_the_click_re_checks_the_pr_and_says_why_nothing_started(): void
    {
        $cases = [
            'The PR is no longer open. Nothing to fix.' => ['state' => 'MERGED'],
            'Someone pushed to the branch since. Leaving it to you.' => ['head_sha' => 'bbbbbbb2222'],
            'The PR no longer has conflicts. Nothing to fix.' => ['mergeable' => 'MERGEABLE'],
            'GitHub is still checking, try again in a minute.' => ['mergeable' => 'UNKNOWN'],
        ];
        Queue::fake();

        foreach ($cases as $message => $pr) {
            $run = $this->conflicted();
            $github = $this->github($pr);
            $github->expects('reclaim')->never();

            Livewire::test(RunDetail::class, ['run' => $run])->call('fixConflicts')->assertNoRedirect()->assertSee($message);
            Livewire::test(Dashboard::class)->call('fixConflicts', $run->id)->assertSet('flash', $message);
            $run->delete();
        }

        $this->assertSame(0, Run::count());
        Queue::assertNothingPushed();
    }

    public function test_a_conflict_fix_shows_as_conflict_fix_under_attempts(): void
    {
        $run = $this->conflicted();
        $this->github();
        Queue::fake();

        $fix = $run->fixConflicts(app(GitHub::class));

        $this->assertSame(['Round 1 · Needs you', 'Conflict fix · Waiting'], array_column($fix->attempts(), 'label'));
    }

    public function test_the_run_page_explains_a_merge_conflict_and_a_failed_fix(): void
    {
        Livewire::test(RunDetail::class, ['run' => $this->conflicted()])
            ->assertSeeText('The PR conflicts with main. Choose Fix conflicts to have the agent merge main and resolve them, or fix it yourself.')
            ->assertDontSee('failing checks or open review threads');

        Livewire::test(RunDetail::class, ['run' => $this->conflicted(['stop_reason' => 'conflict_fix_failed', 'kind' => Run::CONFLICT_FIX])])
            ->assertSee('The agent couldn’t resolve the conflicts, so nothing was pushed.')
            ->assertDontSeeHtml('wire:click="fixConflicts"')
            ->assertDontSee('Start over')
            ->assertDontSee('failing checks or open review threads');
    }

    /** A conflict-fix run as the click queues it. */
    private function fixRun(array $attributes = []): Run
    {
        return $this->conflicted([
            'status' => RunStatus::Queued, 'stop_reason' => null, 'finished_at' => null, 'kind' => Run::CONFLICT_FIX,
            'session_id' => null, 'resume_session_id' => 'sess-1', ...$attributes,
        ]);
    }

    /** Runs the job. The PR's head after the push is ccccccc3333 unless the test's GitHub mock says otherwise. */
    private function process(Run $run, ?MockInterface $github = null): Run
    {
        if ($github === null) {
            $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
            $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'UNKNOWN', 'head_sha' => 'ccccccc3333']);
        }

        File::ensureDirectoryExists($run->repository->worktreePath(7));   // the fake prepare builds nothing
        (new ProcessRun($run))->handle($github);

        return $run->fresh();
    }

    /** The agent ends by writing this result file. */
    private function agentWrites(array $result): void
    {
        $this->claude->onAgent = function (array $call) use ($result) {
            File::ensureDirectoryExists("{$call['worktree']}/.foreman");
            File::put("{$call['worktree']}/".AgentReport::RESULT_FILE, json_encode($result));

            return FakeClaude::result();
        };
    }

    private function resolves(): void
    {
        $this->agentWrites(['outcome' => 'pr', 'title' => 'x', 'body' => 'x']);
    }

    /** A GitHub mock that fails the test on any comment, and expects the human label once. */
    private function silentGitHub(): MockInterface
    {
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'UNKNOWN', 'head_sha' => 'aaaaaaa1111']);
        $github->expects('flagForHuman')->never();
        $github->expects('commentOnPullRequest')->never();
        $github->expects('addHumanLabel')->once()->withArgs(fn ($repo, $issue) => $issue === 7);

        return $github;
    }

    private function assertFailedFix(Run $run, string $explanation): void
    {
        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('conflict_fix_failed', $run->stop_reason);
        $this->assertFalse($this->git->didPush(), 'nothing is pushed');
        $this->assertFalse($this->git->ran('remove'), 'the worktree is kept');
        $this->assertStringContainsString($explanation, $run->messages()->where('role', RunMessage::AGENT)->value('body'));
    }

    public function test_a_conflict_is_resolved_by_the_resumed_agent_and_pushed_without_force(): void
    {
        $this->resolves();
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'UNKNOWN', 'head_sha' => 'ccccccc3333']);
        $github->expects('release')->once();

        $run = $this->process($this->fixRun(), $github);

        $this->assertSame(['prepare', 'setup', 'mergeInBase'], array_values(array_intersect($this->git->calls, ['prepare', 'setup', 'mergeInBase'])), 'rebuilt, set up on the clean head, then merged');
        $this->assertTrue($this->git->ran('push'));
        $this->assertCount(1, $this->claude->agentCalls);
        $call = $this->claude->agentCalls[0];
        $this->assertSame('sess-1', $call['resume']);
        $this->assertStringContainsString('app/Foo.php', $call['prompt']);
        $this->assertStringContainsString('origin/main', $call['prompt']);
        $this->assertSame(RunStatus::WatchingPr, $run->status);
        $this->assertSame('ccccccc3333', $run->head_sha);
        $this->assertSame(1, $run->pr_round);
        $this->assertSame(Run::CONFLICT_FIX, $run->kind);
    }

    public function test_the_worktree_is_rebuilt_even_when_the_old_one_is_still_there(): void
    {
        $this->resolves();
        $run = $this->process($this->fixRun());

        $this->assertTrue($this->git->ran('prepare'));
        $this->assertFalse($this->git->ran('catchUp:fetch'), 'never the catch-up merge, which aborts on a conflict');
        $this->assertSame($run->repository->worktreePath(7), $run->worktree_path);
    }

    public function test_a_head_that_moved_before_the_agent_starts_spends_no_tokens(): void
    {
        $this->git->head = 'eeeeeee5555';

        $run = $this->process($this->fixRun());

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('someone_pushed', $run->stop_reason);
        $this->assertSame([], $this->claude->agentCalls);
        $this->assertFalse($this->git->ran('setup'));
        $this->assertFalse($this->git->didPush());
    }

    public function test_a_clean_merge_is_pushed_with_no_agent(): void
    {
        $this->git->merge = ['base' => 'bbbbbbb0000', 'conflicts' => [], 'error' => null];

        $run = $this->process($this->fixRun());

        $this->assertSame([], $this->claude->agentCalls);
        $this->assertTrue($this->git->ran('push'));
        $this->assertSame(RunStatus::WatchingPr, $run->status);
    }

    public function test_a_merge_that_fails_for_another_reason_fails_the_fix(): void
    {
        $this->git->merge = ['base' => 'bbbbbbb0000', 'conflicts' => [], 'error' => 'fatal: refusing to merge unrelated histories'];

        $run = $this->process($this->fixRun(), $this->silentGitHub());

        $this->assertFailedFix($run, 'Foreman: ');
        $this->assertFailedFix($run, 'unrelated histories');
        $this->assertSame([], $this->claude->agentCalls);
    }

    public function test_an_agent_that_gives_up_fails_the_fix_with_its_text_in_the_thread(): void
    {
        $this->agentWrites(['outcome' => 'needs_human', 'reason' => 'unclear', 'comment' => 'Both sides rewrote the parser.']);
        $run = $this->process($this->fixRun(), $this->silentGitHub());
        $this->assertFailedFix($run, 'Both sides rewrote the parser.');

        $this->agentWrites(['outcome' => 'question', 'questions' => ['Keep which parser?']]);
        $this->git->calls = [];
        $github = $this->silentGitHub();
        $run = $this->process($this->fixRun(['issue_number' => 7]), $github);
        $this->assertFailedFix($run, 'Keep which parser?');
    }

    public function test_the_push_is_refused_when_a_check_fails(): void
    {
        $cases = [
            'isn\'t in the branch' => fn () => $this->git->containsBase = false,
            'Conflict markers are still in app/Foo.php' => fn () => $this->git->markers = ['app/Foo.php'],
            'Locked file changed' => fn () => $this->git->changedFiles = ['config/locked.php'],
        ];

        foreach ($cases as $explanation => $arrange) {
            $this->git->containsBase = true;
            $this->git->markers = [];
            $this->git->changedFiles = [];
            $this->git->calls = [];
            $arrange();
            $this->resolves();

            $run = $this->process($this->fixRun(), $this->silentGitHub());

            $this->assertFailedFix($run, $explanation);
        }
    }

    public function test_a_refused_push_after_someone_pushed_leaves_the_pr_to_you(): void
    {
        $this->resolves();
        $this->git->pushFailure = 'Pushing agent/issue-7 failed: rejected (fetch first)';
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'UNKNOWN', 'head_sha' => 'fffffff6666']);

        $run = $this->process($this->fixRun(), $github);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('someone_pushed', $run->stop_reason);
    }

    public function test_a_refused_push_while_the_remote_stayed_put_fails_the_fix(): void
    {
        $this->resolves();
        $this->git->pushFailure = 'Pushing agent/issue-7 failed: rejected (non-fast-forward)';

        $run = $this->process($this->fixRun(), $this->silentGitHub());

        $this->assertFailedFix($run, 'Foreman: Pushing agent/issue-7 failed');
    }

    public function test_a_missing_session_gets_one_fresh_try_with_the_issue_and_both_sides(): void
    {
        $this->claude->onAgent = function (array $call) {
            if ($call['resume'] !== null) {
                throw new SessionNotFound('Claude found no conversation for session sess-1.');
            }

            File::ensureDirectoryExists("{$call['worktree']}/.foreman");
            File::put("{$call['worktree']}/".AgentReport::RESULT_FILE, json_encode(['outcome' => 'pr', 'title' => 'x', 'body' => 'x']));

            return FakeClaude::result();
        };
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn(['state' => 'OPEN', 'mergeable' => 'UNKNOWN', 'head_sha' => 'ccccccc3333']);
        $github->allows('sanitisedIssue')->andReturn('<untrusted_issue>Fix the parser</untrusted_issue>');
        $this->git->sides = ['ours' => '+agent line', 'theirs' => '+main line'];

        $run = $this->process($this->fixRun(), $github);

        $this->assertCount(2, $this->claude->agentCalls);
        $fresh = $this->claude->agentCalls[1];
        $this->assertNull($fresh['resume']);
        $this->assertStringContainsString('Fix the parser', $fresh['prompt']);
        $this->assertStringContainsString('+agent line', $fresh['prompt']);
        $this->assertStringContainsString('+main line', $fresh['prompt']);
        $this->assertStringContainsString('app/Foo.php', $fresh['prompt']);
        $this->assertSame(RunStatus::WatchingPr, $run->status);
    }

    public function test_any_other_crash_is_not_retried_and_fails_the_fix(): void
    {
        $this->claude->onAgent = fn () => throw new RuntimeException('Hit the 45-minute limit.');

        $run = $this->process($this->fixRun(), $this->silentGitHub());

        $this->assertCount(1, $this->claude->agentCalls);
        $this->assertFailedFix($run, 'Foreman: Hit the 45-minute limit.');
    }

    public function test_a_failed_setup_script_fails_the_fix(): void
    {
        Process::fake(fn () => Process::result('', 'npm ERR!', 1));

        $run = $this->process($this->fixRun(), $this->silentGitHub());

        $this->assertFailedFix($run, 'Foreman: Running setup script failed');
    }

    public function test_a_cancelled_conflict_fix_ends_as_cancelled(): void
    {
        $run = $this->fixRun();
        $this->claude->onAgent = function () use ($run) {
            $run->update(['cancel_requested' => true]);

            throw new RuntimeException('Cancelled');
        };
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->expects('addHumanLabel')->never();
        $github->expects('flagForHuman')->never();

        $run = $this->process($run, $github);

        $this->assertSame(RunStatus::Cancelled, $run->status);
    }

    public function test_a_new_conflict_after_a_successful_fix_offers_the_button_again(): void
    {
        $fix = $this->conflicted(['kind' => Run::CONFLICT_FIX]);   // the watcher stopped the fix run on a fresh conflict

        Livewire::test(RunDetail::class, ['run' => $fix])->assertSeeHtml('wire:click="fixConflicts"');
    }

    public function test_a_failed_fixs_explanation_shows_in_the_thread_not_as_a_question(): void
    {
        $run = $this->conflicted(['stop_reason' => 'conflict_fix_failed', 'kind' => Run::CONFLICT_FIX]);
        $run->messages()->create(['role' => RunMessage::AGENT, 'body' => 'Foreman: Hit the 45-minute limit.']);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSee('Foreman: Hit the 45-minute limit.')
            ->assertDontSee('Question 1');
    }
}
