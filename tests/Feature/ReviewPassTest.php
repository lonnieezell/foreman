<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\Dashboard;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Models\RunMessage;
use App\Services\AgentReport;
use App\Services\GitHub;
use App\Support\IssueSanitiser;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\Support\FakeClaude;
use Tests\Support\FakeWorktree;
use Tests\TestCase;

class ReviewPassTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE_DIFF = "diff --git a/routes/web.php b/routes/web.php\n--- a/routes/web.php\n+++ b/routes/web.php\n@@ -1 +1 @@\n+Route::get('/x');\n";

    private const CODE_DIFF = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1 @@\n+return 1;\n";

    private const TEST_DIFF = "diff --git a/tests/ATest.php b/tests/ATest.php\n--- a/tests/ATest.php\n+++ b/tests/ATest.php\n@@ -1 +1 @@\n+\$this->assertTrue(true);\n";

    /** What the review passes answer with when each runs in a session of its own. */
    private const FRESH = [
        'review' => ['type' => 'result', 'num_turns' => 2, 'total_cost_usd' => 0.5, 'session_id' => 'sess-review'],
        'security' => ['type' => 'result', 'num_turns' => 3, 'total_cost_usd' => 0.25, 'session_id' => 'sess-security'],
    ];

    private string $worktree;

    /** @var list<string> What ran, in order: issue, review, security, gate. */
    private array $sequence = [];

    /** @var list<list<string>> Every command the job ran. */
    private array $commands = [];

    private FakeWorktree $git;

    private FakeClaude $claude;

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = FakeWorktree::install($this->app);
        $this->claude = FakeClaude::install($this->app);

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
     * @param  array{review?:array,security?:array,reviewResult?:array}  $answers  What the passes answer with.
     * @param  Closure|null  $before  Gets the run before the job starts.
     * @return array{0:Run,1:list<string>} The run and the comments flagged for a human.
     */
    private function settle(string $diff, array $answers = [], array $repository = [], ?Closure $before = null): array
    {
        $answers += [
            'review' => ['type' => 'result', 'num_turns' => 2, 'total_cost_usd' => 0.5, 'session_id' => 'sess-1'],
            'security' => ['type' => 'result', 'num_turns' => 3, 'total_cost_usd' => 0.25, 'session_id' => 'sess-1'],
            'reviewResult' => null,
        ];

        $this->git->diff = $diff;

        $run = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'verify_script' => 'composer test', 'watch_prs' => false, ...$repository,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
        ]);

        $this->claude->onAgent = function (array $call) use ($answers) {
            $pass = match (true) {
                str_starts_with($call['prompt'], '/security-review') => 'security',
                str_contains($call['prompt'], 'code-review skill') => 'review',
                str_contains($call['prompt'], 'scope judge vetoed') => 'correction',
                default => 'issue',
            };
            $this->sequence[] = $pass;

            $result = $pass === 'review' && $answers['reviewResult'] ? $answers['reviewResult'] : ['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.'];
            File::ensureDirectoryExists("{$this->worktree}/.foreman");
            File::put("{$this->worktree}/".AgentReport::RESULT_FILE, json_encode($result));

            $event = $answers[$pass] ?? ['type' => 'result', 'num_turns' => 1, 'total_cost_usd' => 1, 'session_id' => 'sess-1'];

            // The real adapter saves each streamed event's session id on the run as it goes, unless told not to
            if ($call['saveSession'] && isset($event['session_id'])) {
                $call['run']->update(['session_id' => $event['session_id']]);
            }

            return FakeClaude::result($event);
        };

        Process::fake(function (PendingProcess $process) {
            $command = $this->commands[] = $process->command;

            if ($command[0] === 'bash') {
                $this->sequence[] = 'gate';
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

        if ($before) {
            $before($run);
        }

        (new ProcessRun($run))->handle($github);

        return [$run->fresh(), $comments ?? []];
    }

    private function prompt(string $needle): ?string
    {
        return $this->claude->agentCall($needle)['prompt'] ?? null;
    }

    private function ran(array $prefix): bool
    {
        return collect($this->commands)->contains(fn ($c) => array_slice($c, 0, count($prefix)) === $prefix);
    }

    /** A sandboxed agent can't reach gpg-agent, so Foreman signs what it hasn't pushed yet, outside the sandbox. */
    public function test_a_sandboxed_run_pushes_with_signing(): void
    {
        $this->settle(self::TEST_DIFF, repository: ['sandbox' => true]);

        $this->assertTrue($this->git->ran('push:sign'));
    }

    public function test_an_unsandboxed_run_pushes_without_signing(): void
    {
        $this->settle(self::TEST_DIFF);

        $this->assertTrue($this->git->ran('push'));
        $this->assertFalse($this->git->ran('push:sign'));
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

    public function test_a_risk_diff_that_fails_is_assessed_as_tier_1_instead_of_failing_the_run(): void
    {
        $this->git->diffFailure = 'fatal: bad revision';

        [$run] = $this->settle(self::TEST_DIFF);

        $this->assertSame(1, $run->risk_tier);
        $this->assertStringContainsString('`low --fix origin/main`', $this->prompt('code-review skill'));
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

    public function test_the_review_pass_and_security_review_each_start_a_fresh_session(): void
    {
        [$run] = $this->settle(self::ROUTE_DIFF, self::FRESH);

        $this->assertNull($this->claude->agentCall('code-review skill')['resume']);
        $this->assertNull($this->claude->agentCall('/security-review')['resume']);
        $this->assertSame('sess-1', $run->session_id);
        $this->assertStringContainsString('The review pass ran in a fresh session (sess-review).', File::get($run->logPath()));
        $this->assertStringContainsString('/security-review ran in a fresh session (sess-security).', File::get($run->logPath()));
    }

    public function test_a_fresh_pass_that_fails_still_leaves_the_implementation_session(): void
    {
        [$run] = $this->settle(self::CODE_DIFF, ['review' => ['type' => 'result', 'is_error' => true, 'subtype' => 'error_max_turns', 'session_id' => 'sess-review']]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('sess-1', $run->session_id);
    }

    public function test_the_correction_round_still_resumes_the_implementation_session(): void
    {
        $verdicts = 0;
        $this->claude->onOneShot = function () use (&$verdicts) {
            return ++$verdicts === 1
                ? ['adds_unrequested' => true, 'misses_requested' => false, 'weakens_tests' => false, 'reason' => 'Adds a cache.']
                : ['adds_unrequested' => false, 'misses_requested' => false, 'weakens_tests' => false, 'reason' => 'Fine now.'];
        };

        [$run] = $this->settle(self::CODE_DIFF, self::FRESH);

        $this->assertSame(['issue', 'gate', 'review', 'correction', 'gate'], $this->sequence);
        $this->assertSame('sess-1', $this->claude->agentCall('scope judge vetoed')['resume']);
        $this->assertSame(RunStatus::PrOpened, $run->status);
    }

    public function test_both_fresh_prompts_carry_the_issue_the_checks_and_the_rules(): void
    {
        $this->settle(self::ROUTE_DIFF);

        foreach (['code-review skill', '/security-review'] as $pass) {
            $prompt = $this->prompt($pass);

            $this->assertStringContainsString('Issue text', $prompt, $pass);
            $this->assertStringContainsString(IssueSanitiser::TEXT_NOTE, $prompt, $pass);
            $this->assertStringContainsString("The issue is the spec. Don't remove or change behavior it asks for.", $prompt, $pass);
            $this->assertStringContainsString("```bash\ncomposer test\n```", $prompt, $pass);
            $this->assertStringNotContainsString('same way as before', $prompt, $pass);
            $this->assertStringContainsString('You cannot push, open PRs or use `gh`.', $prompt, $pass);
            $this->assertStringContainsString('Bash runs only commands on an allowlist.', $prompt, $pass);
            $this->assertStringContainsString('`test_looks_wrong`', $prompt, $pass);
            $this->assertStringContainsString('name the file and lines, the finding, and the choices', $prompt, $pass);
            $this->assertStringContainsString('committed, left uncommitted, or not made', $prompt, $pass);
        }

        $this->assertStringContainsString('{"outcome": "question", "questions": ["<question>"]}', $this->prompt('code-review skill'));
        $this->assertStringContainsString('{"outcome": "needs_human", "reason": "<short reason>", "comment": "<comment for the issue>"}', $this->prompt('/security-review'));
    }

    public function test_without_a_verify_script_the_fresh_prompts_point_at_the_projects_own_checks(): void
    {
        File::delete("{$this->worktree}/composer.json");

        $this->settle(self::CODE_DIFF, repository: ['verify_script' => '']);

        $this->assertStringContainsString('Run the checks that AGENTS.md or CLAUDE.md lists', $this->prompt('code-review skill'));
    }

    public function test_the_fresh_prompts_carry_the_operators_earlier_decisions(): void
    {
        $this->settle(self::ROUTE_DIFF, before: function (Run $run) {
            $earlier = $run->repository->runs()->create([
                'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
                'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::NeedsHuman,
            ]);
            $earlier->messages()->createMany([
                ['role' => RunMessage::AGENT, 'body' => 'Which cache?'],
                ['role' => RunMessage::HUMAN, 'body' => 'Use Redis.'],
            ]);
        });

        foreach (['code-review skill', '/security-review'] as $pass) {
            $this->assertStringContainsString("Operator answered:\nUse Redis.", $this->prompt($pass), $pass);
        }
    }

    public function test_a_custom_prompt_template_is_noted_in_the_log(): void
    {
        [$run] = $this->settle(self::CODE_DIFF, repository: ['prompt_template' => 'Do {{issue}}.']);

        $this->assertStringContainsString("The review passes don't use this repository's custom prompt.", File::get($run->logPath()));
    }

    public function test_a_review_pass_that_changes_the_pr_result_has_it_restored(): void
    {
        [$run] = $this->settle(self::CODE_DIFF, ['reviewResult' => ['outcome' => 'pr', 'title' => 'Reviewer title', 'body' => 'Reviewer body.']]);

        $create = collect($this->commands)->first(fn ($c) => array_slice($c, 0, 3) === ['gh', 'pr', 'create']);
        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertContains('T', $create);
        $this->assertNotContains('Reviewer title', $create);
        $this->assertStringContainsString('The review pass changed '.AgentReport::RESULT_FILE.'. Foreman put it back.', File::get($run->logPath()));
    }

    public function test_a_question_from_the_review_pass_is_kept(): void
    {
        [$run] = $this->settle(self::CODE_DIFF, ['reviewResult' => ['outcome' => 'question', 'questions' => ['app/A.php:1 returns 1. Keep it?']]]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame(['app/A.php:1 returns 1. Keep it?'], $run->messages()->pluck('body')->all());
    }

    public function test_the_fresh_passes_add_to_the_runs_cost_turns_and_tokens(): void
    {
        $usage = ['input_tokens' => 10, 'output_tokens' => 20, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 40];

        [$run] = $this->settle(self::ROUTE_DIFF, [
            'issue' => ['type' => 'result', 'num_turns' => 1, 'total_cost_usd' => 1, 'session_id' => 'sess-1', 'usage' => $usage],
            'review' => [...self::FRESH['review'], 'usage' => $usage],
            'security' => [...self::FRESH['security'], 'usage' => $usage],
        ]);

        $this->assertEquals(1.75, (float) $run->cost_usd);
        $this->assertSame(6, $run->num_turns);
        $this->assertSame([30, 60, 90, 120], [$run->input_tokens, $run->output_tokens, $run->cache_read_tokens, $run->cache_write_tokens]);
    }

    public function test_the_pass_gets_what_is_left_of_the_spend_cap(): void
    {
        $this->settle(self::CODE_DIFF, repository: ['max_budget_per_run' => '3.00']);

        $this->assertEquals(2.0, $this->claude->agentCall('code-review skill')['budget']);
    }

    public function test_a_spent_cap_stops_the_run_before_the_pass(): void
    {
        [$run, $comments] = $this->settle(self::CODE_DIFF, repository: ['max_budget_per_run' => '1.00']);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(['issue', 'gate'], $this->sequence);
        $this->assertStringContainsString('spend cap', $comments[0]);
        $this->assertFalse($this->git->didPush());
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
        $this->assertFalse($this->git->didPush());
    }

    public function test_a_security_review_that_does_nothing_stops_the_push(): void
    {
        [$run, $comments] = $this->settle(self::ROUTE_DIFF, ['security' => ['type' => 'result', 'num_turns' => 0, 'total_cost_usd' => 0, 'result' => '']]);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('/security-review', $comments[0]);
        $this->assertFalse($this->git->didPush());
    }

    public function test_origin_head_is_pointed_at_the_base_for_the_security_review(): void
    {
        $this->settle(self::ROUTE_DIFF);

        $this->assertTrue($this->git->ran('pointOriginHead'));
    }

    public function test_origin_head_is_left_alone_when_there_is_no_security_review(): void
    {
        $this->settle(self::CODE_DIFF);

        $this->assertFalse($this->git->ran('pointOriginHead'));
    }

    public function test_a_pass_that_asks_for_a_human_is_flagged_and_not_pushed(): void
    {
        [$run, $comments] = $this->settle(self::CODE_DIFF, ['reviewResult' => ['outcome' => 'needs_human', 'reason' => 'unsure', 'comment' => 'Need a call on X.']]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame(['Need a call on X.'], $comments);
        $this->assertFalse($this->git->didPush());
    }

    public function test_the_tier_shows_on_the_dashboard_and_the_run_page(): void
    {
        [$run] = $this->settle(self::ROUTE_DIFF);

        Livewire::test(Dashboard::class)->assertSee('Tier 3');
        Livewire::test(RunDetail::class, ['run' => $run])->assertSeeInOrder(['Risk tier', '3']);
    }
}
