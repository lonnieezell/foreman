<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Dashboard;
use App\Livewire\History;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryTest extends TestCase
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

    private function makeRun(Repository $repo, int $issue, RunStatus $status = RunStatus::PrReady, array $extra = []): Run
    {
        return $repo->runs()->create([
            'issue_number' => $issue, 'issue_title' => "Title {$issue}", 'issue_url' => "https://example.test/issues/{$issue}",
            'branch' => "agent/issue-{$issue}", 'model' => 'sonnet', 'status' => $status, ...$extra,
        ]);
    }

    public function test_an_issue_with_several_runs_is_one_row_linking_to_its_latest_run(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 7, RunStatus::Failed);
        $latest = $this->makeRun($repo, 7, RunStatus::PrReady);

        $html = Livewire::test(History::class)->html();

        $this->assertSame(1, substr_count($html, 'Title 7'));
        $this->assertStringContainsString(route('runs.show', $latest), $html);
        $this->assertStringContainsString('PR ready', $html);
    }

    public function test_the_same_issue_number_in_two_repositories_gets_two_rows(): void
    {
        $this->makeRun($this->repository('one'), 3);
        $this->makeRun($this->repository('two'), 3);

        $this->assertSame(2, substr_count(Livewire::test(History::class)->html(), 'Title 3'));
    }

    public function test_turns_cost_and_tokens_are_totals_across_the_issues_runs(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 5, RunStatus::Failed, ['num_turns' => 10, 'cost_usd' => 1.25, 'input_tokens' => 1000, 'cache_read_tokens' => 500, 'cache_write_tokens' => 500, 'output_tokens' => 300]);
        $this->makeRun($repo, 5, RunStatus::PrReady, ['num_turns' => 12, 'cost_usd' => 2.5, 'input_tokens' => 2000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 700]);

        Livewire::test(History::class)
            ->assertSeeHtml('<td>2</td>')
            ->assertSeeHtml('<td>22</td>')
            ->assertSee('$3.75')
            ->assertSeeHtml('title="4,000"')
            ->assertSeeHtml('title="1,000"');
    }

    public function test_newest_activity_comes_first(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1);
        $this->makeRun($repo, 2);
        $this->makeRun($repo, 1, RunStatus::Failed);

        Livewire::test(History::class)->assertSeeInOrder(['Title 1', 'Title 2']);
    }

    public function test_it_filters_by_repository_including_archived_ones(): void
    {
        $this->makeRun($this->repository('live'), 1);
        $archived = $this->repository('old', archived: true);
        $this->makeRun($archived, 2);

        Livewire::test(History::class)->assertSee('Title 1')->assertSee('Title 2')
            ->set('repositoryId', $archived->id)
            ->assertDontSee('Title 1')->assertSee('Title 2');
    }

    public function test_the_filter_is_kept_in_the_url_and_survives_pagination(): void
    {
        $repo = $this->repository('live');
        $other = $this->repository('other');
        foreach (range(1, 30) as $issue) {
            $this->makeRun($repo, $issue);
        }
        $this->makeRun($other, 99);

        $this->get("/history?repository={$repo->id}")->assertSee('Title 30')->assertDontSee('Title 99');

        Livewire::withQueryParams(['repository' => $repo->id])->test(History::class)
            ->assertSet('repositoryId', $repo->id)
            ->assertSeeHtml('&quot;as&quot;:&quot;repository&quot;')
            ->assertSee('Title 30')
            ->call('gotoPage', 2)
            ->assertSet('repositoryId', $repo->id)
            ->assertSee('Title 1')->assertDontSee('Title 30')->assertDontSee('Title 99');
    }

    public function test_it_paginates(): void
    {
        $repo = $this->repository();
        foreach (range(1, 30) as $issue) {
            $this->makeRun($repo, $issue);
        }

        Livewire::test(History::class)->assertSee('Title 30')->assertDontSee('Title 5')
            ->call('gotoPage', 2)->assertSee('Title 5')->assertDontSee('Title 30');
    }

    public function test_the_dashboard_and_masthead_link_to_history(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Cancelled);

        Livewire::test(Dashboard::class)->set('showHandled', true)->assertSee(route('history'), false)->assertSee('View all history');
        $this->get('/archive')->assertSee('>History</a>', false);
    }
}
