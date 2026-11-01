<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Livewire\RunDetail;
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

class ReviewPassTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE_DIFF = "diff --git a/routes/web.php b/routes/web.php\n--- a/routes/web.php\n+++ b/routes/web.php\n@@ -1 +1 @@\n+Route::get('/x');\n";

    private const CODE_DIFF = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1 @@\n+return 1;\n";

    private const TEST_DIFF = "diff --git a/tests/ATest.php b/tests/ATest.php\n--- a/tests/ATest.php\n+++ b/tests/ATest.php\n@@ -1 +1 @@\n+\$this->assertTrue(true);\n";

    private string $worktree;

    /** @var list<string> What ran, in order: issue, review, security, gate. */
    private array $sequence = [];

    /** @var list<list<string>> Every command the job ran. */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->worktree = sys_get_temp_dir().'/foreman-review-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
        file_put_contents("{$this->worktree}/composer.json", json_encode(['require' => ['laravel/framework' => '*']]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    /**
     * Runs the job to a `pr` result against faked commands.
     *
     * @param  array{review?:array,security?:array,reviewResult?:array,originHead?:bool}  $answers  What the passes answer with.
     * @return array{0:Run,1:list<string>} The run and the comments flagged for a human.
     */
    private function settle(string $diff, array $answers = [], array $repository = []): array
    {
        $answers += [
            'review' => ['type' => 'result', 'num_turns' => 2, 'total_cost_usd' => 0.5, 'session_id' => 'sess-1'],
            'security' => ['type' => 'result', 'num_turns' => 3, 'total_cost_usd' => 0.25, 'session_id' => 'sess-1'],
            'reviewResult' => null,
            'originHead' => true,
            'gpgsign' => 'true',
            'pushed' => true,
        ];

        $run = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'verify_script' => 'composer test', 'watch_prs' => false, ...$repository,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
        ]);

        Process::fake(function (PendingProcess $process) use ($diff, $answers) {
            $command = $this->commands[] = $process->command;

            if (self::isScopeJudge($process)) {
                return Process::result(self::SCOPE_JUDGE_PASS);
            }

            if ($command[0] === 'claude') {
                $prompt = $command[2] ?? '';
                $pass = match (true) {
                    str_starts_with($prompt, '/security-review') => 'security',
                    str_contains($prompt, 'code-review skill') => 'review',
                    default => 'issue',
                };
                $this->sequence[] = $pass;

                $result = $pass === 'review' && $answers['reviewResult'] ? $answers['reviewResult'] : ['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.'];
                File::ensureDirectoryExists("{$this->worktree}/.foreman");
                File::put("{$this->worktree}/".ProcessRun::RESULT_FILE, json_encode($result));

                return Process::result(json_encode($answers[$pass] ?? ['type' => 'result', 'num_turns' => 1, 'total_cost_usd' => 1, 'session_id' => 'sess-1']));
            }

            if ($command[0] === 'bash') {
                $this->sequence[] = 'gate';
            }

            if (array_slice($command, 0, 3) === ['git', 'diff', '-U0']) {
                return Process::result($diff);
            }

            if (array_slice($command, 0, 3) === ['git', 'rev-parse', '--verify'] && end($command) === 'refs/remotes/origin/HEAD') {
                return Process::result('', '', $answers['originHead'] ? 0 : 1);
            }

            if ($command === ['git', 'rev-parse', '--verify', '--quiet', 'origin/agent/issue-7']) {
                return Process::result('', '', $answers['pushed'] ? 0 : 1);
            }

            if ($command === ['git', 'merge-base', 'HEAD', 'origin/main']) {
                return Process::result("base-sha\n");
            }

            if (in_array('--git-common-dir', $command, true)) {
                return Process::result("/repo/.git/worktrees/issue-7\n/repo/.git\n");
            }

            if ($command === ['git', 'config', '--type=bool', '--get', 'commit.gpgsign']) {
                return Process::result("{$answers['gpgsign']}\n");
            }

            if (array_slice($command, 0, 3) === ['gh', 'pr', 'create']) {
                return Process::result("https://github.com/owner/app/pull/12\n");
            }

            return Process::result('');
        });

        $github = Mockery::mock(GitHub::class)->makePartial();
        $github->allows('release');
        $github->allows('labels')->andReturn([]);
        $github->allows('sanitisedIssue')->andReturn('Issue text');
        $github->allows('trustedComments')->andReturn('');
        $github->allows('flagForHuman')->andReturnUsing(function ($repo, $issue, $comment) use (&$comments) {
            $comments[] = $comment;
        });

        (new ProcessRun($run))->handle($github);

        return [$run->fresh(), $comments ?? []];
    }

    /** The first claude call whose prompt holds `$needle`. */
    private function claudeCommand(string $needle): array
    {
        return collect($this->commands)->first(fn ($c) => $c[0] === 'claude' && str_contains($c[2] ?? '', $needle)) ?? [];
    }

    private function prompt(string $needle): ?string
    {
        return $this->claudeCommand($needle)[2] ?? null;
    }

    private function ran(array $prefix): bool
    {
        return collect($this->commands)->contains(fn ($c) => array_slice($c, 0, count($prefix)) === $prefix);
    }

    /** A sandboxed agent can't reach gpg-agent, so Foreman signs every commit it hasn't pushed yet, outside the sandbox. */
    public function test_a_sandboxed_run_signs_the_commits_not_yet_pushed_before_it_pushes(): void
    {
        $this->settle(self::TEST_DIFF, repository: ['sandbox' => true]);

        $sign = array_search(['git', 'rebase', '--force-rebase', '--rebase-merges', '--autostash', '--gpg-sign', 'origin/agent/issue-7'], $this->commands, true);
        $push = collect($this->commands)->search(fn ($c) => array_slice($c, 0, 2) === ['git', 'push']);
        $this->assertNotFalse($sign, 'commits after the pushed branch are re-signed');
        $this->assertLessThan($push, $sign);
    }

    public function test_a_branch_never_pushed_is_signed_from_where_it_left_the_base(): void
    {
        $this->settle(self::TEST_DIFF, ['pushed' => false], ['sandbox' => true]);

        $this->assertTrue($this->ran(['git', 'rebase', '--force-rebase', '--rebase-merges', '--autostash', '--gpg-sign', 'base-sha']));
    }

    public function test_commits_are_not_re_signed_when_unsandboxed_or_when_signing_is_off(): void
    {
        $this->settle(self::TEST_DIFF);
        $this->settle(self::TEST_DIFF, ['gpgsign' => 'false'], ['sandbox' => true]);

        $this->assertFalse($this->ran(['git', 'rebase']));
    }

    public function test_a_tier_1_change_gets_a_low_effort_code_review_and_simplify_but_no_security_review(): void
    {
        [$run] = $this->settle(self::TEST_DIFF);

        $this->assertSame(1, $run->risk_tier);
        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertStringContainsString('`low --fix origin/main`', $this->prompt('code-review skill'));
        $this->assertStringContainsString('simplify skill', $this->prompt('code-review skill'));
        $this->assertSame(['issue', 'gate', 'review', 'gate'], $this->sequence);
    }

    public function test_a_tier_2_change_gets_a_medium_code_review_and_simplify(): void
    {
        [$run] = $this->settle(self::CODE_DIFF);

        $this->assertSame(2, $run->risk_tier);
        $this->assertStringContainsString('`medium --fix origin/main`', $this->prompt('code-review skill'));
        $this->assertStringContainsString('simplify skill', $this->prompt('code-review skill'));
        $this->assertSame(['issue', 'gate', 'review', 'gate'], $this->sequence);
    }

    public function test_a_tier_3_change_gets_a_high_code_review_then_a_security_review_then_the_gate_again(): void
    {
        [$run] = $this->settle(self::ROUTE_DIFF);

        $this->assertSame(3, $run->risk_tier);
        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertStringContainsString('`high --fix origin/main`', $this->prompt('code-review skill'));
        $this->assertStringContainsString('touches routes/web.php', $this->prompt('/security-review'));
        $this->assertSame(['issue', 'gate', 'review', 'security', 'gate'], $this->sequence);
    }

    public function test_the_passes_resume_the_agents_session_and_stay_in_reviewing(): void
    {
        $this->settle(self::ROUTE_DIFF);

        $review = $this->claudeCommand('code-review skill');
        $this->assertSame('sess-1', $review[array_search('--resume', $review, true) + 1]);
        $security = $this->claudeCommand('/security-review');
        $this->assertSame('sess-1', $security[array_search('--resume', $security, true) + 1]);
    }

    public function test_the_passes_add_to_the_runs_cost_and_turns(): void
    {
        [$run] = $this->settle(self::ROUTE_DIFF);

        $this->assertEquals(1.75, (float) $run->cost_usd);
        $this->assertSame(6, $run->num_turns);
    }

    public function test_the_pass_gets_what_is_left_of_the_spend_cap(): void
    {
        $this->settle(self::CODE_DIFF, repository: ['max_budget_per_run' => '3.00']);

        $review = $this->claudeCommand('code-review skill');
        $this->assertSame('2', $review[array_search('--max-budget-usd', $review, true) + 1]);
    }

    public function test_a_spent_cap_stops_the_run_before_the_pass(): void
    {
        [$run, $comments] = $this->settle(self::CODE_DIFF, repository: ['max_budget_per_run' => '1.00']);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(['issue', 'gate'], $this->sequence);
        $this->assertStringContainsString('spend cap', $comments[0]);
        $this->assertFalse($this->ran(['git', 'push']));
    }

    public function test_the_tier_is_added_as_a_pr_label(): void
    {
        $this->settle(self::ROUTE_DIFF);

        $this->assertTrue($this->ran(['gh', 'label', 'create', 'risk:tier-3']));
        $this->assertTrue($this->ran(['gh', 'pr', 'edit', '12', '--add-label', 'risk:tier-3', '--repo', 'owner/app']));
    }

    public function test_a_review_pass_that_errors_stops_the_push(): void
    {
        [$run, $comments] = $this->settle(self::CODE_DIFF, ['review' => ['type' => 'result', 'is_error' => true, 'subtype' => 'error_max_turns', 'total_cost_usd' => 0.1]]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('error_max_turns', $comments[0]);
        $this->assertFalse($this->ran(['git', 'push']));
    }

    public function test_a_security_review_that_does_nothing_stops_the_push(): void
    {
        [$run, $comments] = $this->settle(self::ROUTE_DIFF, ['security' => ['type' => 'result', 'num_turns' => 0, 'total_cost_usd' => 0, 'result' => '']]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('/security-review', $comments[0]);
        $this->assertFalse($this->ran(['git', 'push']));
    }

    public function test_a_missing_origin_head_is_pointed_at_the_base_before_the_security_review(): void
    {
        $this->settle(self::ROUTE_DIFF, ['originHead' => false], ['base_branch' => 'develop']);

        $this->assertTrue($this->ran(['git', 'remote', 'set-head', 'origin', 'develop']));
    }

    public function test_an_existing_origin_head_is_left_alone(): void
    {
        $this->settle(self::ROUTE_DIFF);

        $this->assertFalse($this->ran(['git', 'remote', 'set-head']));
    }

    public function test_a_pass_that_asks_for_a_human_is_flagged_and_not_pushed(): void
    {
        [$run, $comments] = $this->settle(self::CODE_DIFF, ['reviewResult' => ['outcome' => 'needs_human', 'reason' => 'unsure', 'comment' => 'Need a call on X.']]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame(['Need a call on X.'], $comments);
        $this->assertFalse($this->ran(['git', 'push']));
    }

    public function test_the_tier_shows_on_the_dashboard_and_the_run_page(): void
    {
        [$run] = $this->settle(self::ROUTE_DIFF);

        Livewire::test(Dashboard::class)->assertSee('Tier 3');
        Livewire::test(RunDetail::class, ['run' => $run])->assertSeeInOrder(['Risk tier', '3']);
    }
}
