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

        $html = Str::after(Livewire::test(Dashboard::class)->set('showHandled', true)->html(), '>Issues</h2>');

        $this->assertSeeInOrder($html, ['Needs your reply', 'Title 1', 'Which database?', 'Failed', 'Title 2', 'Setup script failed', 'Ready for review', 'Title 4', 'Title 3', 'In progress', 'Title 23', 'Title 22', 'Title 21', 'Title 20', 'Watching PRs', 'Title 5', 'Handled', 'Title 6']);

        $this->assertStringContainsString('Title 1', Str::between($html, 'Needs your reply', 'Failed'));
        $this->assertStringContainsString('Title 2', Str::between($html, 'Failed', 'Ready for review'));
        $this->assertStringContainsString('Title 3', Str::between($html, 'Ready for review', 'In progress'));
        $this->assertStringContainsString('Title 4', Str::between($html, 'Ready for review', 'In progress'));
        $this->assertStringContainsString('Title 5', Str::between($html, 'Watching PRs', 'Handled'));
        $this->assertStringContainsString('Title 6', Str::after($html, 'Handled'));

        foreach ([20, 21, 22, 23] as $active) {
            $this->assertStringContainsString("Title {$active}", Str::between($html, 'In progress', 'Watching PRs'));
        }
    }

    public function test_cards_show_their_actions(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::NeedsHuman, ['session_id' => 'sess-1', 'worktree_path' => '/tmp/wt']);
        $this->makeRun($repo, 2, RunStatus::Failed, ['error' => 'boom']);
        $this->makeRun($repo, 3, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/3']);
        $this->makeRun($repo, 5, RunStatus::WatchingPr, ['pr_url' => 'https://example.test/pull/5']);
        $this->makeRun($repo, 6, RunStatus::Cancelled);
        Process::fake();

        Livewire::test(Dashboard::class)->set('showHandled', true)
            ->assertSeeHtml('href="https://example.test/issues/1"')
            ->assertSeeHtml('wire:click="continueRun('.Run::where('issue_number', 1)->value('id').')"')
            ->assertSeeHtml('wire:click="retryRun('.Run::where('issue_number', 2)->value('id').')"')
            ->assertSeeHtml('href="https://example.test/pull/3"')
            ->assertDontSeeHtml('href="https://example.test/pull/5"')
            ->assertSeeHtml('wire:click="retryRun('.Run::where('issue_number', 6)->value('id').')"');
    }

    public function test_continue_after_my_reply_follows_the_run_page_rules(): void
    {
        $repo = $this->repository();
        $withPr = $this->makeRun($repo, 1, RunStatus::NeedsHuman, ['session_id' => 's', 'worktree_path' => '/tmp/wt', 'pr_url' => 'https://example.test/pull/1']);
        $noSession = $this->makeRun($repo, 2, RunStatus::NeedsHuman, ['worktree_path' => '/tmp/wt']);
        $noWorktree = $this->makeRun($repo, 3, RunStatus::NeedsHuman, ['session_id' => 's']);
        $ok = $this->makeRun($repo, 4, RunStatus::NeedsHuman, ['session_id' => 's', 'worktree_path' => '/tmp/wt']);
        Process::fake();

        $test = Livewire::test(Dashboard::class);

        foreach ([$withPr, $noSession, $noWorktree] as $run) {
            $test->assertDontSeeHtml('wire:click="continueRun('.$run->id.')"');
        }
        $test->assertSeeHtml('wire:click="continueRun('.$ok->id.')"');
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

        Livewire::test(Dashboard::class)->set('showHandled', true)
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

    public function test_continuing_a_needs_you_issue_from_the_dashboard_starts_a_resumed_run_and_stays_on_the_dashboard(): void
    {
        $repo = $this->repository();
        $run = $this->makeRun($repo, 1, RunStatus::NeedsHuman, ['session_id' => 'sess-1', 'worktree_path' => '/tmp/wt']);
        $github = Mockery::mock(GitHub::class);
        $github->expects('reclaim')->once();
        $this->app->instance(GitHub::class, $github);
        Queue::fake();
        Process::fake();

        Livewire::test(Dashboard::class)->call('continueRun', $run->id)
            ->assertNoRedirect()
            ->assertSet('flash', "#1 is continuing after your reply in {$repo->name}.");

        $next = Run::find($run->id + 1);
        $this->assertSame(RunStatus::Queued, $next->status);
        $this->assertSame('sess-1', $next->resume_session_id);
        Queue::assertPushedOn('runs', ProcessRun::class);
    }

    public function test_dismiss_is_offered_on_attention_cards_and_moves_the_issue_to_handled(): void
    {
        $repo = $this->repository();
        $runs = [
            $this->makeRun($repo, 1, RunStatus::NeedsHuman),
            $this->makeRun($repo, 2, RunStatus::Failed, ['error' => 'boom']),
            $this->makeRun($repo, 3, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/3']),
            $this->makeRun($repo, 4, RunStatus::PrOpened),
        ];
        $this->makeRun($repo, 5, RunStatus::WatchingPr);
        $this->makeRun($repo, 6, RunStatus::Cancelled);
        Process::fake();

        $component = Livewire::test(Dashboard::class);
        foreach ($runs as $run) {
            $component->assertSeeHtml('wire:click="dismissRun('.$run->id.')"');
        }
        $this->assertSame(4, substr_count($component->html(), 'dismissRun('));

        $component->call('dismissRun', $runs[1]->id)->set('showHandled', true);

        $this->assertNotNull($runs[1]->fresh()->handled_at);
        $html = Str::after($component->html(), '>Issues</h2>');
        $this->assertStringNotContainsString('Title 2', Str::before($html, 'Handled'));
        $this->assertStringContainsString('Title 2', Str::after($html, 'Handled'));
        $this->assertStringNotContainsString('class="issue-section-title">Failed', $html);
    }

    public function test_handled_starts_collapsed_and_toggles_open(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::Cancelled);
        Process::fake();

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('aria-expanded="false"')
            ->assertSee('Handled')
            ->assertDontSee('Title 1')
            ->assertDontSee('View all history')
            ->toggle('showHandled')
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSee('Title 1')
            ->assertSee('View all history')
            ->toggle('showHandled')
            ->assertDontSee('Title 1');
    }

    public function test_handled_labels_dismissed_and_cancelled_and_offers_restore_only_for_dismissed(): void
    {
        $repo = $this->repository();
        $failed = $this->makeRun($repo, 1, RunStatus::Failed, ['handled_at' => now()]);
        $cancelled = $this->makeRun($repo, 2, RunStatus::Cancelled);
        Process::fake();

        Livewire::test(Dashboard::class)->set('showHandled', true)
            ->assertSeeHtmlInOrder(['>Cancelled</span>', 'Title 2', 'Title 1', 'Dismissed</p>'])
            ->assertDontSeeHtml('Cancelled</p>')
            ->assertSeeHtml('wire:click="restoreRun('.$failed->id.')"')
            ->assertDontSeeHtml('wire:click="restoreRun('.$cancelled->id.')"')
            ->assertDontSeeHtml('wire:click="dismissRun(');
    }

    public function test_restore_returns_the_issue_to_its_section(): void
    {
        $repo = $this->repository();
        $run = $this->makeRun($repo, 1, RunStatus::PrReady, ['pr_url' => 'https://example.test/pull/1', 'handled_at' => now()]);
        Process::fake();

        $component = Livewire::test(Dashboard::class)->assertDontSee('Ready for review')->call('restoreRun', $run->id);

        $this->assertNull($run->fresh()->handled_at);
        $component->assertSee('Ready for review')->assertSeeHtml('wire:click="dismissRun('.$run->id.')"');
    }

    public function test_dismiss_and_restore_write_nothing_to_github(): void
    {
        $run = $this->makeRun($this->repository(), 1, RunStatus::Failed);
        $github = Mockery::mock(GitHub::class);
        $github->shouldNotReceive('requeue', 'reclaim');
        $this->app->instance(GitHub::class, $github);
        Process::fake();

        Livewire::test(Dashboard::class)->call('dismissRun', $run->id)->call('restoreRun', $run->id);

        // The dashboard's own health checks run `gh auth status`; anything else would touch GitHub
        Process::assertNotRan(fn ($process) => $process->command[0] === 'gh' && $process->command !== ['gh', 'auth', 'status']);
    }

    public function test_a_new_run_supersedes_a_dismissal(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed, ['handled_at' => now()]);
        $this->makeRun($repo, 1, RunStatus::NeedsHuman);
        Process::fake();

        $html = $this->issuesColumn();

        $this->assertSame(1, substr_count($html, 'Title 1'));
        $this->assertStringContainsString('Needs your reply', $html);
        $this->assertStringNotContainsString('Handled', $html);
    }

    public function test_dismissing_a_stale_card_does_not_hide_a_newer_run(): void
    {
        $repo = $this->repository();
        $old = $this->makeRun($repo, 1, RunStatus::Failed);
        $new = $this->makeRun($repo, 1, RunStatus::NeedsHuman);
        Process::fake();

        Livewire::test(Dashboard::class)->call('dismissRun', $old->id);

        $this->assertNull($old->fresh()->handled_at);
        $this->assertNull($new->fresh()->handled_at);
    }

    public function test_the_stat_counts_every_card_in_the_attention_sections_and_breaks_it_down(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::NeedsHuman);
        $this->makeRun($repo, 2, RunStatus::NeedsHuman);
        $this->makeRun($repo, 3, RunStatus::Failed);
        $this->makeRun($repo, 4, RunStatus::PrReady);
        $this->makeRun($repo, 5, RunStatus::PrOpened);
        $this->makeRun($repo, 6, RunStatus::PrOpened);
        $this->makeRun($repo, 7, RunStatus::WatchingPr);
        $this->makeRun($repo, 8, RunStatus::Cancelled);

        Livewire::test(Dashboard::class)
            ->assertSee('6 need you')
            ->assertSee('2 need reply · 1 failed · 3 to review')
            ->assertSeeHtml('href="#issues"')
            ->assertSeeHtml('id="issues"')
            ->assertJs('document.title = "(6) Foreman"');
    }

    public function test_the_caption_leaves_out_empty_parts(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed);

        Livewire::test(Dashboard::class)
            ->assertSee('1 needs you')
            ->assertSee('1 failed')
            ->assertDontSee('need reply')
            ->assertDontSee('to review');
    }

    public function test_the_count_uses_each_issues_latest_run_and_skips_dismissed_and_archived(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed);
        $this->makeRun($repo, 1, RunStatus::PrReady);        // issue 1 now counts once, as ready
        $this->makeRun($repo, 2, RunStatus::Failed, ['handled_at' => now()]);
        $this->makeRun($this->repository('old', archived: true), 3, RunStatus::Failed);

        Livewire::test(Dashboard::class)
            ->assertSee('1 needs you')
            ->assertSee('1 to review')
            ->assertDontSee('1 failed');
    }

    public function test_the_stat_is_hidden_and_the_title_plain_when_nothing_needs_you(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::WatchingPr);

        Livewire::test(Dashboard::class)
            ->assertDontSeeHtml('href="#issues"')
            ->assertJs('document.title = "Foreman"');
    }

    public function test_the_title_follows_the_count_on_the_next_poll(): void
    {
        $repo = $this->repository();
        $run = $this->makeRun($repo, 1, RunStatus::Failed);

        $component = Livewire::test(Dashboard::class)->assertJs('document.title = "(1) Foreman"');

        $run->update(['handled_at' => now()]);

        $component->call('$refresh')->assertJs('document.title = "Foreman"');
    }
}
