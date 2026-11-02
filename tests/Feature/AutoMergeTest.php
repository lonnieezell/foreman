<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
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
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoMergeTest extends TestCase
{
    use RefreshDatabase;

    private const PR = 'https://github.com/owner/app/pull/12';

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->worktree = sys_get_temp_dir().'/foreman-auto-merge-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
        File::put("{$this->worktree}/composer.json", json_encode(['require' => ['laravel/framework' => '*']]));
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

    private function watchedRun(int $tier = 1, array $repository = ['auto_merge_max_tier' => 1], array $attributes = []): Run
    {
        return $this->repository($repository)->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'risk_tier' => $tier,
            'status' => RunStatus::WatchingPr, 'pr_url' => self::PR, 'head_sha' => 'aaaaaaa1111',
            'session_id' => 'sess-1', 'worktree_path' => $this->worktree,
            'watch_started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(10),
            ...$attributes,
        ]);
    }

    private function pr(array $overrides = []): array
    {
        return [
            'state' => 'OPEN',
            'is_draft' => false,
            'head_sha' => 'aaaaaaa1111',
            'checks' => [['name' => 'tests', 'state' => 'passed', 'url' => null]],
            'threads' => [],
            'human_threads' => 0,
            'changes_requested' => false,
            'bot_activity_at' => null,
            ...$overrides,
        ];
    }

    /** A `gh pr diff` that rates as the given tier: docs only is 1, app code is 2, a Laravel route is 3. */
    private static function diff(int $tier): string
    {
        $path = [1 => 'docs/guide.md', 2 => 'app/Foo.php', 3 => 'routes/web.php'][$tier];

        return "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n@@ -1,0 +1,1 @@\n+x\n";
    }

    private function github(array $pr, int $diffTier): MockInterface
    {
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('pullRequest')->andReturn($pr);
        $github->allows('pullRequestDiff')->andReturn(self::diff($diffTier));
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

    public function test_a_new_repository_has_auto_merge_off_and_merges_with_merge(): void
    {
        $repo = $this->repository()->fresh();

        $this->assertNull($repo->auto_merge_max_tier);
        $this->assertSame('merge', $repo->merge_method);
    }

    public function test_the_form_saves_auto_merge_and_the_method(): void
    {
        $repo = $this->repository(['watch_prs' => true]);
        $this->app->instance(GitHub::class, Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('auto_merge_max_tier', '')
            ->assertSet('merge_method', 'merge')
            ->set('auto_merge_max_tier', '2')
            ->set('merge_method', 'squash')
            ->call('save')
            ->assertHasNoErrors();

        $repo = $repo->fresh();
        $this->assertSame(2, $repo->auto_merge_max_tier);
        $this->assertSame('squash', $repo->merge_method);

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->assertSet('auto_merge_max_tier', '2')
            ->set('auto_merge_max_tier', '')
            ->call('save');

        $this->assertNull($repo->fresh()->auto_merge_max_tier);
    }

    #[DataProvider('invalidValues')]
    public function test_validation_rejects_other_auto_merge_values(string $value): void
    {
        $repo = $this->repository(['watch_prs' => true]);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->set('auto_merge_max_tier', $value)
            ->call('save')
            ->assertHasErrors('auto_merge_max_tier');

        $this->assertNull($repo->fresh()->auto_merge_max_tier);
    }

    public static function invalidValues(): array
    {
        return ['three' => ['3'], 'four' => ['4'], 'zero' => ['0'], 'text' => ['all']];
    }

    public function test_validation_rejects_auto_merge_without_watch_prs(): void
    {
        $repo = $this->repository(['watch_prs' => true]);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->set('watch_prs', false)
            ->set('auto_merge_max_tier', '1')
            ->call('save')
            ->assertHasErrors(['auto_merge_max_tier' => 'Auto-merge needs Watch PRs.']);

        $this->assertNull($repo->fresh()->auto_merge_max_tier);
    }

    public function test_validation_rejects_an_unknown_merge_method(): void
    {
        $repo = $this->repository();

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->set('merge_method', 'admin')
            ->call('save')
            ->assertHasErrors('merge_method');
    }

    public function test_a_ready_tier_1_pr_is_merged(): void
    {
        $github = $this->github($this->pr(), 1);
        $github->expects('mergePullRequest')->withArgs(fn ($repo, $url, $method, $sha) => $url === self::PR && $method === 'merge' && $sha === 'aaaaaaa1111')->andReturnNull()->ordered();
        $github->expects('commentOnPullRequest')->withArgs(fn ($repo, $url, $body) => $url === self::PR
            && $body === 'Auto-merged by Foreman: tier 1, checks passed, no open threads.')->ordered();
        $github->expects('labelPullRequest')->with(Mockery::any(), self::PR, 'auto-merged')->ordered();

        $run = $this->check($this->watchedRun());

        $this->assertSame(RunStatus::Merged, $run->status);
        $this->assertSame('Merged', $run->status->label());
        $this->assertSame('good', $run->status->tone());
        $this->assertStringContainsString('Auto-merged (merge) at aaaaaaa, tier 1.', $run->tail());
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_fix_up_round_keeps_the_risk_tier(): void
    {
        $this->github($this->pr(['checks' => [['name' => 'tests', 'state' => 'failed', 'url' => null]]]), 1);

        $this->check($this->watchedRun(2));

        $this->assertSame(2, Run::latest('id')->first()->risk_tier);
    }

    public function test_a_failed_merge_leaves_no_auto_merged_comment(): void
    {
        $github = $this->github($this->pr(), 1);
        $github->expects('mergePullRequest')->andReturn('not mergeable');
        $github->expects('commentOnPullRequest')->withArgs(fn ($repo, $url, $body) => str_contains($body, 'Auto-merge failed'))->once();

        $this->assertSame(RunStatus::NeedsHuman, $this->check($this->watchedRun())->status);
    }

    public function test_it_merges_with_the_chosen_method(): void
    {
        $github = $this->github($this->pr(), 2);
        $github->expects('mergePullRequest')->with(Mockery::any(), self::PR, 'squash', 'aaaaaaa1111')->andReturnNull();

        $run = $this->check($this->watchedRun(2, ['auto_merge_max_tier' => 2, 'merge_method' => 'squash']));

        $this->assertSame(RunStatus::Merged, $run->status);
        $this->assertStringContainsString('Auto-merged (squash) at aaaaaaa, tier 2.', $run->tail());
    }

    public function test_a_tier_2_pr_is_not_merged_when_only_tier_1_is_allowed(): void
    {
        $github = $this->github($this->pr(), 2);
        $github->expects('mergePullRequest')->never();

        $this->assertSame(RunStatus::PrReady, $this->check($this->watchedRun(2))->status);
    }

    public function test_a_tier_3_pr_is_never_merged(): void
    {
        $github = $this->github($this->pr(), 3);
        $github->expects('mergePullRequest')->never();

        $this->assertSame(RunStatus::PrReady, $this->check($this->watchedRun(3, ['auto_merge_max_tier' => 2]))->status);
    }

    public function test_a_tier_that_rose_above_the_setting_needs_you(): void
    {
        $github = $this->github($this->pr(), 3);
        $github->expects('swapPullRequestLabel')->with(Mockery::any(), self::PR, 'risk:tier-1', 'risk:tier-3');
        $github->expects('commentOnPullRequest')->withArgs(fn ($repo, $url, $body) => str_contains($body, 'Tier rose from 1 to 3 after fix-up rounds.'));
        $github->expects('addHumanLabel')->withArgs(fn ($repo, $issue) => $issue === 7);
        $github->expects('mergePullRequest')->never();

        $run = $this->check($this->watchedRun(1, ['auto_merge_max_tier' => 2]));

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('tier_rose', $run->stop_reason);
        $this->assertSame(3, $run->risk_tier);
    }

    public function test_a_tier_that_rose_within_the_setting_is_updated_and_merged(): void
    {
        $github = $this->github($this->pr(), 2);
        $github->expects('swapPullRequestLabel')->with(Mockery::any(), self::PR, 'risk:tier-1', 'risk:tier-2');
        $github->expects('mergePullRequest')->andReturnNull();

        $run = $this->check($this->watchedRun(1, ['auto_merge_max_tier' => 2]));

        $this->assertSame(RunStatus::Merged, $run->status);
        $this->assertSame(2, $run->risk_tier);
        $this->assertStringContainsString('tier 2.', $run->tail());
    }

    #[DataProvider('humanReviewBlocks')]
    public function test_human_review_activity_blocks_the_merge(array $pr): void
    {
        $github = $this->github($this->pr($pr), 1);
        $github->expects('commentOnPullRequest')->once();
        $github->expects('addHumanLabel')->withArgs(fn ($repo, $issue) => $issue === 7);
        $github->expects('mergePullRequest')->never();

        $run = $this->check($this->watchedRun());

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('human_review', $run->stop_reason);
    }

    public static function humanReviewBlocks(): array
    {
        return [
            'a person’s open thread' => [['human_threads' => 1]],
            'changes requested' => [['changes_requested' => true]],
            'a draft' => [['is_draft' => true]],
        ];
    }

    public function test_a_paused_foreman_holds_the_merge_then_merges_after_the_pause(): void
    {
        Setting::put('paused', 1);
        $github = $this->github($this->pr(), 1);
        $github->expects('mergePullRequest')->once()->andReturnNull();
        $run = $this->watchedRun();

        $this->assertSame(RunStatus::WatchingPr, $this->check($run)->status);
        $this->assertSame(RunStatus::WatchingPr, $this->check($run)->status);
        $this->assertSame(1, substr_count($run->tail(), 'Ready to auto-merge; held while Foreman is paused.'));

        Setting::put('paused', 0);

        $this->assertSame(RunStatus::Merged, $this->check($run)->status);
    }

    public function test_turning_auto_merge_off_stops_a_pending_merge(): void
    {
        $github = $this->github($this->pr(), 1);
        $github->expects('mergePullRequest')->never();
        $run = $this->watchedRun();

        Repository::query()->update(['auto_merge_max_tier' => null]);

        $this->assertSame(RunStatus::PrReady, $this->check($run)->status);
    }

    public function test_auto_merge_off_behaves_as_before(): void
    {
        $github = $this->github($this->pr(), 1);
        $github->expects('pullRequestDiff')->never();
        $github->expects('mergePullRequest')->never();

        $run = $this->check($this->watchedRun(1, []));

        $this->assertSame(RunStatus::PrReady, $run->status);
        $this->assertSame(1, $run->risk_tier);
    }

    public function test_a_failed_merge_needs_you_with_the_error_on_the_pr(): void
    {
        $github = $this->github($this->pr(), 1);
        $github->allows('commentOnPullRequest')->withArgs(fn ($repo, $url, $body) => str_starts_with($body, 'Auto-merged by Foreman'));
        $github->expects('mergePullRequest')->once()->andReturn('Pull request is not mergeable: approving review required');
        $github->expects('commentOnPullRequest')->once()->withArgs(fn ($repo, $url, $body) => str_contains($body, 'approving review required'));
        $github->expects('addHumanLabel')->withArgs(fn ($repo, $issue) => $issue === 7);
        $github->expects('labelPullRequest')->never();

        $run = $this->check($this->watchedRun());

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('merge_failed', $run->stop_reason);
    }

    public function test_a_human_merge_still_ends_as_pr_ready(): void
    {
        $github = $this->github($this->pr(['state' => 'MERGED']), 1);
        $github->expects('mergePullRequest')->never();

        $this->assertSame(RunStatus::PrReady, $this->check($this->watchedRun())->status);
    }

    public function test_merge_runs_gh_without_admin_or_branch_deletion(): void
    {
        Process::fake();

        $error = app(GitHub::class)->mergePullRequest($this->repository(), self::PR, 'rebase', 'abc123');

        $this->assertNull($error);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['gh', 'pr', 'merge', '12', '--rebase', '--match-head-commit', 'abc123', '--repo', 'owner/app']);
    }

    public function test_a_failed_gh_merge_returns_its_error_text(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'base branch policy prohibits the merge', exitCode: 1)]);

        $this->assertSame('base branch policy prohibits the merge', app(GitHub::class)->mergePullRequest($this->repository(), self::PR, 'merge', 'abc123'));
    }

    public function test_pull_request_reports_drafts_human_threads_and_change_requests(): void
    {
        $bot = ['__typename' => 'Bot', 'login' => 'coderabbitai'];
        $alice = ['__typename' => 'User', 'login' => 'alice'];
        $bob = ['__typename' => 'User', 'login' => 'bob'];
        $thread = fn (string $id, bool $resolved, array $author) => ['id' => $id, 'isResolved' => $resolved, 'path' => 'a.php', 'line' => 1,
            'comments' => ['nodes' => [['author' => $author, 'body' => 'b', 'url' => "u/{$id}"]]]];
        $review = fn (array $author, string $state) => ['author' => $author, 'state' => $state, 'submittedAt' => '2026-10-31T10:00:00Z'];
        $fake = fn (array $threads, array $reviews, bool $draft) => Process::fake(['*' => Process::result(json_encode(['data' => ['repository' => ['pullRequest' => [
            'state' => 'OPEN', 'isDraft' => $draft, 'headRefOid' => 'abc123',
            'commits' => ['nodes' => []],
            'reviewThreads' => ['nodes' => $threads],
            'comments' => ['nodes' => []],
            'reviews' => ['nodes' => $reviews],
        ]]]]))]);

        $fake([$thread('T1', false, $alice), $thread('T2', true, $bob), $thread('T3', false, $bot)],
            [$review($alice, 'CHANGES_REQUESTED'), $review($alice, 'COMMENTED'), $review($bot, 'CHANGES_REQUESTED')], true);
        $pr = app(GitHub::class)->pullRequest($this->repository(), self::PR);

        $this->assertTrue($pr['is_draft']);
        $this->assertSame(1, $pr['human_threads']);
        $this->assertTrue($pr['changes_requested']);

        // A later approval from the same person lifts the request. A bot's request never counts.
        $fake([], [$review($alice, 'CHANGES_REQUESTED'), $review($alice, 'APPROVED'), $review($bot, 'CHANGES_REQUESTED')], false);
        $pr = app(GitHub::class)->pullRequest($this->repository(), self::PR);

        $this->assertFalse($pr['is_draft']);
        $this->assertSame(0, $pr['human_threads']);
        $this->assertFalse($pr['changes_requested']);
    }
}
