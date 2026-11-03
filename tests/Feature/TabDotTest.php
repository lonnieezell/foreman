<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TabDotTest extends TestCase
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

    private function assertDot(?string $dot): void
    {
        $this->getJson(route('tab-dot'))->assertOk()->assertExactJson(['dot' => $dot]);
    }

    public function test_no_runs_means_no_dot(): void
    {
        $this->assertDot(null);
    }

    public function test_a_failed_run_is_bad(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::Failed);

        $this->assertDot('bad');
    }

    public function test_a_run_waiting_on_a_reply_needs_attention(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::NeedsHuman);

        $this->assertDot('attention');
    }

    #[TestWith([RunStatus::PrReady])]
    #[TestWith([RunStatus::PrOpened])]
    public function test_a_pr_ready_for_review_is_good(RunStatus $status): void
    {
        $this->makeRun($this->repository(), 1, $status);

        $this->assertDot('good');
    }

    public function test_a_run_at_any_station_is_busy(): void
    {
        foreach (RunStatus::stations() as $i => $station) {
            Run::query()->delete();
            $this->makeRun($this->repository("app{$i}"), 1, $station);

            $this->assertDot('busy');
        }
    }

    public function test_failed_beats_a_reply_beats_review_beats_busy(): void
    {
        $repo = $this->repository();
        $failed = $this->makeRun($repo, 1, RunStatus::Failed);
        $reply = $this->makeRun($repo, 2, RunStatus::NeedsHuman);
        $review = $this->makeRun($repo, 3, RunStatus::PrReady);
        $this->makeRun($repo, 4, RunStatus::Working);

        $this->assertDot('bad');
        $failed->delete();
        $this->assertDot('attention');
        $reply->delete();
        $this->assertDot('good');
        $review->delete();
        $this->assertDot('busy');
    }

    public function test_only_the_latest_run_of_an_issue_counts(): void
    {
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed);
        $this->makeRun($repo, 1, RunStatus::Working);

        $this->assertDot('busy');
    }

    public function test_dismissed_runs_and_archived_repositories_do_not_count(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::Failed, ['handled_at' => now()]);
        $this->makeRun($this->repository('old', archived: true), 2, RunStatus::NeedsHuman);

        $this->assertDot(null);
    }

    #[TestWith([RunStatus::Merged])]
    #[TestWith([RunStatus::Cancelled])]
    public function test_a_finished_issue_has_no_dot(RunStatus $status): void
    {
        $this->makeRun($this->repository(), 1, $status);

        $this->assertDot(null);
    }

    public function test_watching_a_pr_is_not_busy(): void
    {
        $this->makeRun($this->repository(), 1, RunStatus::WatchingPr);

        $this->assertDot(null);
    }
}
