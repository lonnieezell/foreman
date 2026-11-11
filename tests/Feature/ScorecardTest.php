<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Scorecard;
use App\Services\RunScorer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScorecardTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Run ids restart in the test database, so keep log files away from the real ones
        $this->storage = sys_get_temp_dir().'/foreman-tests-'.getmypid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function makeRun(RunStatus $status = RunStatus::PrOpened, array $attributes = []): Run
    {
        $repo = Repository::firstOrCreate(['name' => 'app'], [
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);

        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => $status, 'finished_at' => now(), ...$attributes,
        ]);
    }

    /** Copies `tests/Fixtures/transcripts/<name>.jsonl` and `.log` to the run's log paths. A side with no fixture gets one line that counts for nothing. */
    private function giveLogs(Run $run, string $name): void
    {
        File::ensureDirectoryExists(dirname($run->logPath()));
        $filler = ['jsonl' => '{"type":"system","subtype":"init"}', 'log' => '[00:00:00] Starting'];

        foreach (['jsonl' => $run->rawLogPath(), 'log' => $run->logPath()] as $extension => $path) {
            $fixture = base_path("tests/Fixtures/transcripts/{$name}.{$extension}");
            File::put($path, File::exists($fixture) ? File::get($fixture) : $filler[$extension]."\n");
        }
    }

    public static function facts(): array
    {
        return [
            'tool errors by tool' => ['tool-errors', ['tool_errors' => ['Bash' => 2, 'Edit' => 1]]],
            // The same lines again, not a big file read in pages
            'a file read 3 or more times' => ['repeated-reads', ['repeated_reads' => ['/wt/app/Models/Run.php' => 3]]],
            // Commands that only name a test tool, like `cat phpunit.xml`, don't count
            'full versus filtered test runs' => ['test-runs', ['full_test_runs' => 2, 'filtered_test_runs' => 3]],
            'stop-hook blocks' => ['stop-hook', ['stop_hook_blocks' => 2]],
            // The gate's failure doesn't name its check, so it doesn't count
            'verify failures' => ['verify-failures', ['verify_failures' => 1]],
            'a scope-judge veto' => ['scope-veto', ['scope_vetoes' => 1]],
            // Two written, one edited in, one more by rewriting the file, one TypeScript and one Rust unit test.
            // Not app code, and a removal adds none.
            'test methods added' => ['test-methods', ['test_methods_added' => 6]],
        ];
    }

    #[DataProvider('facts')]
    public function test_the_scorer_measures_each_fact_from_the_logs(string $fixture, array $expected): void
    {
        $run = $this->makeRun();
        $this->giveLogs($run, $fixture);

        $scorecard = app(RunScorer::class)->score($run);

        $this->assertSame($expected, $scorecard->only(array_keys($expected)));
        $this->assertNull($scorecard->log_problem);
    }

    public static function brokenLogs(): array
    {
        return [
            'both missing' => [null, null, 'run-%d.jsonl is missing. run-%d.log is missing.'],
            'transcript empty' => ['', '[00:00:00] Starting', 'run-%d.jsonl is empty.'],
            'log empty' => ['{"type":"system"}', "\n", 'run-%d.log is empty.'],
        ];
    }

    #[DataProvider('brokenLogs')]
    public function test_a_missing_or_empty_log_gets_a_scorecard_that_says_so(?string $transcript, ?string $log, string $problem): void
    {
        $run = $this->makeRun();
        File::ensureDirectoryExists(dirname($run->logPath()));

        foreach ([$run->rawLogPath() => $transcript, $run->logPath() => $log] as $path => $contents) {
            if ($contents !== null) {
                File::put($path, $contents);
            }
        }

        $scorecard = app(RunScorer::class)->score($run);

        $this->assertSame(str_replace('%d', (string) $run->id, $problem), $scorecard->log_problem);
        $this->assertSame([], $scorecard->tool_errors);
        $this->assertSame(0, $scorecard->stop_hook_blocks);
    }

    public function test_improve_scores_each_ended_run_once(): void
    {
        $run = $this->makeRun();
        $this->giveLogs($run, 'stop-hook');
        $noLogs = $this->makeRun(RunStatus::Failed);

        $this->artisan('foreman:improve')->assertSuccessful();
        $this->artisan('foreman:improve')->assertSuccessful();

        $this->assertSame(2, $run->scorecard->stop_hook_blocks);
        $this->assertSame("run-{$noLogs->id}.jsonl is missing. run-{$noLogs->id}.log is missing.", $noLogs->scorecard->log_problem);
        $this->assertSame(2, Scorecard::count());
    }

    public static function unscoredStatuses(): array
    {
        return [
            'cancelled' => [RunStatus::Cancelled],
            'queued' => [RunStatus::Queued],
            'working' => [RunStatus::Working],
            'reviewing' => [RunStatus::Reviewing],
            'still watching its PR' => [RunStatus::WatchingPr],
        ];
    }

    #[DataProvider('unscoredStatuses')]
    public function test_improve_never_scores_cancelled_or_unfinished_runs(RunStatus $status): void
    {
        $run = $this->makeRun($status);
        $this->giveLogs($run, 'stop-hook');

        $this->artisan('foreman:improve')->assertSuccessful();

        $this->assertNull($run->scorecard);
    }

    public function test_the_run_page_shows_the_scorecard_once_the_run_is_scored(): void
    {
        $run = $this->makeRun();

        $this->get(route('runs.show', $run))->assertDontSee('Scorecard');

        $run->scorecard()->create([
            'log_problem' => 'run-1.log is empty.',
            'tool_errors' => ['Bash' => 2, 'Edit' => 1], 'repeated_reads' => ['/wt/app/Models/Run.php' => 4],
            'full_test_runs' => 3, 'filtered_test_runs' => 5, 'stop_hook_blocks' => 12, 'verify_failures' => 11,
            'scope_vetoes' => 13, 'test_methods_added' => 16,
        ]);

        $this->get(route('runs.show', $run))
            ->assertSeeInOrder(['Scorecard', 'run-1.log is empty.'])
            ->assertSee('<dt>Tool errors</dt><dd>Bash 2, Edit 1</dd>', false)
            ->assertSee('<dt>Files read 3+ times</dt><dd>/wt/app/Models/Run.php (4)</dd>', false)
            ->assertSee('<dt>Test runs</dt><dd>3 full, 5 filtered</dd>', false)
            ->assertSee('<dt>Stop-hook blocks</dt><dd>12</dd>', false)
            ->assertSee('<dt>Verify failures</dt><dd>11</dd>', false)
            ->assertSee('<dt>Scope-judge vetoes</dt><dd>13</dd>', false)
            ->assertSee('<dt>Test methods added</dt><dd>16</dd>', false);
    }

    public function test_improve_runs_daily_at_3am_in_the_background_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'foreman:improve'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->runInBackground);
    }

    public function test_improve_scores_runs_from_the_last_30_days_and_leaves_older_ones_alone(): void
    {
        $recent = $this->makeRun(attributes: ['finished_at' => now()->subDays(29)]);
        $old = $this->makeRun(attributes: ['finished_at' => now()->subDays(31)]);

        $this->artisan('foreman:improve')->assertSuccessful();

        $this->assertNotNull($recent->scorecard);
        $this->assertNull($old->scorecard);
    }
}
