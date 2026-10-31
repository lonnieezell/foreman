<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RunAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(string $name = 'app'): Repository
    {
        return Repository::create([
            'name' => $name,
            'local_path' => base_path(),
            'github_repo' => "owner/{$name}",
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);
    }

    private function makeRun(Repository $repo, RunStatus $status, int $issue = 7, int $round = 0): Run
    {
        return $repo->runs()->create([
            'issue_number' => $issue, 'issue_title' => 'Fix', 'branch' => "agent/issue-{$issue}",
            'model' => 'sonnet', 'status' => $status, 'pr_round' => $round,
        ]);
    }

    public function test_lists_every_attempt_oldest_first_with_links_and_highlights_the_current_one(): void
    {
        $repo = $this->makeRepo();
        $first = $this->makeRun($repo, RunStatus::Failed);
        $second = $this->makeRun($repo, RunStatus::PrOpened);
        $round = $this->makeRun($repo, RunStatus::WatchingPr, round: 1);

        Livewire::test('run-detail', ['run' => $second])
            ->assertSeeInOrder(['Attempts', 'Run 1 · Failed', 'Run 2 · PR opened', 'Round 1 · Watching PR'])
            ->assertSeeHtml('href="'.route('runs.show', $first).'"')
            ->assertSeeHtml('href="'.route('runs.show', $round).'"')
            ->assertSeeHtml('tone-bad')
            ->assertSeeHtml('aria-current="page"');
    }

    public function test_only_the_viewed_run_is_marked_current(): void
    {
        $repo = $this->makeRepo();
        $this->makeRun($repo, RunStatus::Failed);
        $second = $this->makeRun($repo, RunStatus::PrOpened);

        $html = Livewire::test('run-detail', ['run' => $second])->html();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"\s*>Run 2/', $html);
    }

    public function test_strip_is_hidden_when_the_issue_has_a_single_run(): void
    {
        $run = $this->makeRun($this->makeRepo(), RunStatus::Failed);

        Livewire::test('run-detail', ['run' => $run])->assertDontSee('Attempts');
    }

    public function test_runs_for_the_same_issue_number_in_another_repository_are_excluded(): void
    {
        $run = $this->makeRun($this->makeRepo('app'), RunStatus::Failed);
        $this->makeRun($this->makeRepo('other'), RunStatus::PrOpened);

        Livewire::test('run-detail', ['run' => $run])->assertDontSee('Attempts');
    }

    public function test_runs_for_other_issues_are_excluded(): void
    {
        $repo = $this->makeRepo();
        $run = $this->makeRun($repo, RunStatus::Failed, issue: 7);
        $this->makeRun($repo, RunStatus::Failed, issue: 8);

        Livewire::test('run-detail', ['run' => $run])->assertDontSee('Attempts');
    }
}
