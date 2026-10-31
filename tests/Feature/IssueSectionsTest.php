<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class IssueSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function repository(string $name = 'app', bool $archived = false): Repository
    {
        return Repository::create([
            'name' => $name,
            'local_path' => base_path(),
            'github_repo' => "owner/{$name}",
            'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            'archived_at' => $archived ? now() : null,
        ]);
    }

    private function makeRun(Repository $repo, int $issue, RunStatus $status, array $extra = []): Run
    {
        return $repo->runs()->create([
            'issue_number' => $issue, 'issue_title' => "Title {$issue}", 'issue_url' => "https://example.test/issues/{$issue}",
            'branch' => "agent/issue-{$issue}", 'model' => 'sonnet', 'status' => $status, ...$extra,
        ]);
    }

    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $found = strpos($html, $needle, $offset);
            $this->assertNotFalse($found, "'{$needle}' not found after offset {$offset}");
            $offset = $found + strlen($needle);
        }
    }

    private function issuesColumn(): string
    {
        return Str::after($this->get('/')->getContent(), '>Issues</h2>');
    }

    public function test_an_issue_with_several_runs_appears_once_in_the_section_of_its_latest_run(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 5, RunStatus::Failed, ['error' => 'Old failure reason']);
        $this->makeRun($repo, 5, RunStatus::PrOpened, ['pr_url' => 'https://example.test/pull/5']);
        Process::fake();

        $this->get('/')
            ->assertSee('Ready for review')
            ->assertSeeText('Title 5', false)
            ->assertDontSee('Old failure reason')
            ->assertDontSee('Needs your reply')
            ->assertDontSeeText('Failed');

        $this->assertSame(1, substr_count($this->get('/')->getContent(), 'Title 5'));
    }

    public function test_the_same_issue_number_in_two_repositories_gets_two_entries(): void
    {
        $this->makeRun($this->repository('one'), 5, RunStatus::Failed, ['error' => 'boom']);
        $this->makeRun($this->repository('two'), 5, RunStatus::Failed, ['error' => 'boom']);
        Process::fake();

        $this->assertSame(2, substr_count($this->get('/')->getContent(), 'Title 5'));
    }

    public function test_each_section_holds_exactly_its_statuses_in_order(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::NeedsHuman, ['error' => 'Which database?']);
        $this->makeRun($repo, 2, RunStatus::Failed, ['error' => 'Setup script failed']);
        $this->makeRun($repo, 3, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/3']);
        $this->makeRun($repo, 4, RunStatus::PrOpened, ['pr_url' => 'https://example.test/pull/4']);
        $this->makeRun($repo, 5, RunStatus::WatchingPr);
        $this->makeRun($repo, 6, RunStatus::Cancelled);
        foreach ([RunStatus::Queued, RunStatus::Preparing, RunStatus::Working, RunStatus::Checking] as $i => $status) {
            $this->makeRun($repo, 20 + $i, $status);
        }
        Process::fake();

        $html = $this->issuesColumn();

        $this->assertSeeInOrder($html, ['Needs your reply', 'Title 1', 'Which database?', 'Failed', 'Title 2', 'Setup script failed', 'Ready for review', 'Title 4', 'Title 3', 'Watching PRs', 'Title 5', 'Handled', 'Title 6']);

        $this->assertStringContainsString('Title 1', Str::between($html, 'Needs your reply', 'Failed'));
        $this->assertStringContainsString('Title 2', Str::between($html, 'Failed', 'Ready for review'));
        $this->assertStringContainsString('Title 3', Str::between($html, 'Ready for review', 'Watching PRs'));
        $this->assertStringContainsString('Title 4', Str::between($html, 'Ready for review', 'Watching PRs'));
        $this->assertStringContainsString('Title 5', Str::between($html, 'Watching PRs', 'Handled'));
        $this->assertStringContainsString('Title 6', Str::after($html, 'Handled'));

        foreach ([20, 21, 22, 23] as $active) {
            $this->assertStringNotContainsString("Title {$active}", $html);
        }
    }

    public function test_cards_show_their_actions(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::NeedsHuman);
        $this->makeRun($repo, 2, RunStatus::Failed, ['error' => 'boom']);
        $this->makeRun($repo, 3, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/3']);
        $this->makeRun($repo, 5, RunStatus::WatchingPr, ['pr_url' => 'https://example.test/pull/5']);
        $this->makeRun($repo, 6, RunStatus::Cancelled);
        Process::fake();

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('href="https://example.test/issues/1"')
            ->assertSeeHtml('wire:click="continueRun('.Run::where('issue_number', 1)->value('id').')"')
            ->assertSeeHtml('wire:click="retryRun('.Run::where('issue_number', 2)->value('id').')"')
            ->assertSeeHtml('href="https://example.test/pull/3"')
            ->assertDontSeeHtml('href="https://example.test/pull/5"')
            ->assertSeeHtml('wire:click="retryRun('.Run::where('issue_number', 6)->value('id').')"');
    }

    public function test_a_card_shows_number_repository_title_reason_and_age_and_links_to_the_latest_run(): void
    {
        $this->freezeTime();
        $repo = $this->repository();
        $run = $this->makeRun($repo, 123, RunStatus::Failed, [
            'error' => 'Running setup script failed', 'cost_usd' => 4.56, 'num_turns' => 77,
            'input_tokens' => 1500, 'output_tokens' => 2000, 'finished_at' => now()->subHours(3),
        ]);
        Process::fake();

        $issues = $this->issuesColumn();

        foreach (['#123', 'Title 123', 'Running setup script failed', '3h ago', 'href="'.route('runs.show', $run).'"'] as $expected) {
            $this->assertStringContainsString($expected, $issues);
        }
        foreach (['4.56', 'Turns', '1.5K / 2K', '77'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $issues);
        }
    }

    public function test_attention_sections_include_issues_of_any_age(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed, ['error' => 'ancient', 'created_at' => now()->subMonths(6), 'updated_at' => now()->subMonths(6)]);
        Process::fake();

        $this->get('/')->assertSee('Title 1')->assertSee('ancient');
    }

    public function test_handled_shows_the_ten_newest_cancelled_issues(): void
    {
        $repo = $this->repository();
        foreach (range(1, 12) as $issue) {
            $this->makeRun($repo, $issue, RunStatus::Cancelled);
        }
        Process::fake();

        $this->get('/')
            ->assertSee('Title 12')
            ->assertSee('Title 3')
            ->assertDontSee('Title 2<', false)
            ->assertDontSee('Title 1<', false)
            ->assertSeeInOrder(['Title 12', 'Title 11']);
    }

    public function test_empty_sections_are_hidden_and_nothing_needs_you_shows_when_attention_is_clear(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Cancelled);
        Process::fake();

        $this->get('/')
            ->assertSee('Nothing needs you.')
            ->assertDontSee('Needs your reply')
            ->assertDontSee('Ready for review')
            ->assertDontSee('Watching PRs')
            ->assertSee('Handled');

        $this->makeRun($repo, 2, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/2']);

        $this->get('/')
            ->assertDontSee('Nothing needs you.')
            ->assertSee('Ready for review')
            ->assertDontSee('class="issue-section-title">Failed', false);
    }

    public function test_only_watching_prs_does_not_count_as_needing_you(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::WatchingPr);
        Process::fake();

        $this->get('/')->assertSee('Nothing needs you.')->assertSee('Watching PRs');
    }

    public function test_issues_from_archived_repositories_are_left_out(): void
    {
        $this->makeRun($this->repository('gone', archived: true), 9, RunStatus::Failed, ['error' => 'boom']);
        Process::fake();

        $this->get('/')->assertDontSee('Title 9')->assertSee('Nothing needs you.');
    }

    public function test_done_today_counts_each_issue_once_by_its_latest_run(): void
    {
        $repo = $this->repository();
        $repo->update(['enabled' => true]);
        // One PR with three rounds of fixes, ending as ready
        $this->makeRun($repo, 1, RunStatus::PrOpened, ['finished_at' => now()]);
        $this->makeRun($repo, 1, RunStatus::PrOpened, ['finished_at' => now()]);
        $this->makeRun($repo, 1, RunStatus::PrReady, ['finished_at' => now()]);
        // Failed earlier today, then retried and failed again: one failure
        $this->makeRun($repo, 2, RunStatus::Failed, ['finished_at' => now()]);
        $this->makeRun($repo, 2, RunStatus::Failed, ['finished_at' => now()]);
        // Failed today, retried and now on the line: not counted
        $this->makeRun($repo, 3, RunStatus::Failed, ['finished_at' => now()]);
        $this->makeRun($repo, 3, RunStatus::Working);
        // Finished yesterday: not counted
        $this->makeRun($repo, 4, RunStatus::Failed, ['finished_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        // Failed yesterday, finished today: counted under its latest status
        $this->makeRun($repo, 5, RunStatus::Failed, ['finished_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->makeRun($repo, 5, RunStatus::PrReady, ['finished_at' => now()]);
        Process::fake();

        $counts = Run::doneToday()->get()->groupBy('repository_id')->map->countBy(fn ($run) => $run->status->value);

        $this->assertSame(['pr_ready' => 2, 'failed' => 1], $counts[$repo->id]->all());

        Livewire::test(Dashboard::class)
            ->assertSeeHtmlInOrder(['<dt class="tone-good">2</dt>', '<dd>PR ready</dd>', '<dt class="tone-bad">1</dt>', '<dd>Failed</dd>']);
    }

    public function test_continuing_a_needs_you_issue_from_the_dashboard_starts_a_resumed_run(): void
    {
        $repo = $this->repository();
        $run = $this->makeRun($repo, 1, RunStatus::NeedsHuman, ['session_id' => 'sess-1', 'worktree_path' => '/tmp/wt']);
        $github = Mockery::mock(GitHub::class);
        $github->expects('reclaim')->once();
        $this->app->instance(GitHub::class, $github);
        Queue::fake();
        Process::fake();

        Livewire::test(Dashboard::class)->call('continueRun', $run->id)->assertRedirect(route('runs.show', $run->id + 1));

        $next = Run::find($run->id + 1);
        $this->assertSame(RunStatus::Queued, $next->status);
        $this->assertSame('sess-1', $next->resume_session_id);
        Queue::assertPushedOn('runs', ProcessRun::class);
    }
}
