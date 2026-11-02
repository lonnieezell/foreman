<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ForemanGateTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->worktree = sys_get_temp_dir().'/foreman-gate-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function makeRun(array $repository = []): Run
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'verify_script' => 'composer test', 'watch_prs' => false, ...$repository,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
        ]);
    }

    /**
     * Runs the agent to a `pr` result, with the gate's commands answering as given.
     *
     * @param  array{integrity?:int,verify?:int,numstat?:string,verifyOutput?:string}  $gate
     */
    private function settle(array $gate = [], array $repository = []): array
    {
        $gate += ['integrity' => 0, 'verify' => 0, 'numstat' => "3\t1\tapp/A.php\n", 'verifyOutput' => ''];
        $statusWhileGating = [];
        $run = $this->makeRun($repository);

        Process::fake(function (PendingProcess $process) use ($gate, $run, &$statusWhileGating) {
            $command = $process->command;

            if (self::isScopeJudge($process)) {
                return Process::result(self::SCOPE_JUDGE_PASS);
            }

            if ($command[0] === 'claude') {
                File::ensureDirectoryExists("{$this->worktree}/.foreman");
                File::put("{$this->worktree}/".ProcessRun::RESULT_FILE, json_encode(['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.']));

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 1]));
            }

            if ($command[0] === 'bash' || str_contains((string) ($command[1] ?? ''), 'test-integrity.php')) {
                $statusWhileGating[] = $run->fresh()->status;
                $integrity = $command[0] !== 'bash';

                return Process::result($integrity ? 'Integrity output' : $gate['verifyOutput'], '', $integrity ? $gate['integrity'] : $gate['verify']);
            }

            if (array_slice($command, 0, 3) === ['git', 'diff', '--numstat']) {
                return Process::result($gate['numstat']);
            }

            if (array_slice($command, 0, 3) === ['gh', 'pr', 'create']) {
                return Process::result("https://github.com/owner/app/pull/12\n");
            }

            return Process::result('');
        });

        $github = Mockery::mock(GitHub::class)->makePartial();
        $github->allows('release');
        $github->allows('flagForHuman')->andReturnUsing(function ($repo, $issue, $comment) use (&$comments) {
            $comments[] = $comment;
        });

        (new ProcessRun($run))->handle($github);

        return [$run->fresh(), $comments ?? [], $statusWhileGating];
    }

    private function isPush(PendingProcess $p): bool
    {
        return array_slice($p->command, 0, 2) === ['git', 'push'];
    }

    public function test_a_failing_verify_script_stops_the_push_and_puts_the_output_on_the_issue(): void
    {
        [$run, $comments] = $this->settle(['verify' => 1, 'verifyOutput' => 'FAILED: FooTest::test_it']);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('gate_failed', $run->stop_reason);
        $this->assertNotNull($run->finished_at);
        Process::assertDidntRun(fn (PendingProcess $p) => $this->isPush($p));
        Process::assertDidntRun(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'pr', 'create']);
        $this->assertCount(1, $comments);
        $this->assertStringContainsString('Verify script failed (exit 1)', $comments[0]);
        $this->assertStringContainsString('> FAILED: FooTest::test_it', $comments[0]);
    }

    public function test_the_verify_script_runs_with_ci_set_so_test_runners_do_not_watch(): void
    {
        $this->settle();

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'bash' && ($p->environment['CI'] ?? null) === 'true');
    }

    public function test_a_failing_integrity_check_stops_the_push_and_puts_the_output_on_the_issue(): void
    {
        [$run, $comments] = $this->settle(['integrity' => 1]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        Process::assertDidntRun(fn (PendingProcess $p) => $this->isPush($p));
        $this->assertStringContainsString('Test-integrity check failed (exit 1)', $comments[0]);
        $this->assertStringContainsString('> Integrity output', $comments[0]);
    }

    public function test_without_a_verify_script_the_format_check_covers_only_files_changed_since_the_base(): void
    {
        file_put_contents("{$this->worktree}/composer.json", json_encode(['require-dev' => ['laravel/pint' => '*']]));

        $this->settle(repository: ['verify_script' => null, 'base_branch' => 'develop']);

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'bash'
            && str_contains($p->command[2], "--merge-base 'origin/develop'")
            && str_contains($p->command[2], 'xargs -0 -r vendor/bin/pint --test --'));
        Process::assertDidntRun(fn (PendingProcess $p) => $p->command[0] === 'bash' && str_contains($p->command[2], "\nvendor/bin/pint --test\n"));
    }

    public function test_a_passing_run_goes_through_reviewing_before_the_push(): void
    {
        [$run, $comments, $statusWhileGating] = $this->settle();

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame([], $comments);
        // The gate runs twice: before and after the review pass
        $this->assertSame(array_fill(0, 4, RunStatus::Reviewing), $statusWhileGating);
        Process::assertRan(fn (PendingProcess $p) => $this->isPush($p));
    }

    public function test_the_reviewing_station_shows_on_the_dashboard_track(): void
    {
        $this->assertSame([RunStatus::Queued, RunStatus::Preparing, RunStatus::Working, RunStatus::Checking, RunStatus::Reviewing], RunStatus::stations());
        $this->assertSame('Reviewing', RunStatus::Reviewing->label());
        $this->assertTrue(RunStatus::Reviewing->isActive());

        $run = $this->makeRun();
        $run->moveTo(RunStatus::Reviewing);
        Process::fake();

        Livewire::test(Dashboard::class)->assertSeeInOrder(['Running checks', 'Reviewing']);
    }

    public function test_a_change_over_the_size_limit_goes_to_needs_you_instead_of_opening(): void
    {
        config(['foreman.max_pr_lines' => 10]);

        [$run, $comments] = $this->settle(['numstat' => "6\t4\tapp/A.php\n3\t0\tapp/B.php\n-\t-\timage.png\n"]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        Process::assertDidntRun(fn (PendingProcess $p) => $this->isPush($p));
        $this->assertStringContainsString('the change is 13 changed lines', $comments[0]);
        $this->assertStringContainsString('over the limit of 10', $comments[0]);
    }

    public function test_a_change_at_the_size_limit_still_opens(): void
    {
        config(['foreman.max_pr_lines' => 13]);

        [$run] = $this->settle(['numstat' => "6\t4\tapp/A.php\n3\t0\tapp/B.php\n"]);

        $this->assertSame(RunStatus::PrOpened, $run->status);
    }

    public function test_the_pr_body_ends_with_foremans_footer(): void
    {
        $this->settle();

        Process::assertRan(fn (PendingProcess $p) => collect($p->command)->contains(
            "Closes #7\n\nDone.\n\n---\n**Foreman's gate**, run by Foreman outside the agent before the push:\n\n"
            ."- Verify script: passed (`composer test`)\n- Test integrity: passed\n- Size: 4 changed lines (limit 1000)\n"
            .'- Scope judge: passed. Stays within the issue.'
        ));
    }
}
