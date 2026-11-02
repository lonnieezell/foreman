<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\Support\FakeWorktree;
use Tests\TestCase;

class ScopeJudgeTest extends TestCase
{
    use RefreshDatabase;

    private const DIFF = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1 @@\n+return 1;\n";

    private const PASS = ['in_scope' => true, 'weakens_tests' => false, 'reason' => 'Only fixes the bug the issue names.'];

    private const VETO = ['in_scope' => false, 'weakens_tests' => false, 'reason' => 'Adds an unrelated settings page.'];

    private string $worktree;

    /** @var list<string> What ran, in order: issue, review, judge, correction, gate. */
    private array $sequence = [];

    /** @var list<list<string>> Every command the job ran. */
    private array $commands = [];

    /** @var list<string> The PR bodies Foreman opened. */
    private array $prBodies = [];

    /** @var list<list<string>> Each judge call's command. */
    private array $judgeCommands = [];

    /** @var list<string> What each judge call got on stdin. */
    private array $judgeInputs = [];

    private bool $diffFails = false;

    private bool $gateFails = false;

    private FakeWorktree $git;

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = FakeWorktree::install($this->app);

        $this->worktree = sys_get_temp_dir().'/foreman-judge-'.uniqid();
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
     * @param  list<array|string|null>  $verdicts  What each judge call answers, in order: a verdict, a raw result event (string), or null for a failed call. Past the end, the judge passes.
     * @return array{0:Run,1:list<string>} The run and the comments flagged for a human.
     */
    private function settle(array $verdicts = []): array
    {
        $run = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'verify_script' => 'composer test', 'watch_prs' => false,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
        ]);

        $this->git->diff = self::DIFF;
        $this->git->diffFailure = $this->diffFails ? 'fatal: bad revision' : null;

        Process::fake(function (PendingProcess $process) use (&$verdicts) {
            $command = $this->commands[] = $process->command;

            if (self::isScopeJudge($process)) {
                $this->sequence[] = 'judge';
                $this->judgeCommands[] = $command;
                $this->judgeInputs[] = $process->input;
                $verdict = $verdicts ? array_shift($verdicts) : self::PASS;

                return match (true) {
                    $verdict === null => Process::result('', 'API Error: overloaded', 1),
                    is_string($verdict) => Process::result($verdict),
                    default => Process::result(json_encode(['type' => 'result', 'is_error' => false, 'total_cost_usd' => 0.01, 'structured_output' => $verdict])),
                };
            }

            if ($command[0] === 'claude') {
                $prompt = $command[2] ?? '';
                $this->sequence[] = match (true) {
                    str_contains($prompt, 'code-review skill') => 'review',
                    str_contains($prompt, 'scope judge') => 'correction',
                    default => 'issue',
                };

                File::ensureDirectoryExists("{$this->worktree}/.foreman");
                File::put("{$this->worktree}/".ProcessRun::RESULT_FILE, json_encode(['outcome' => 'pr', 'title' => 'T', 'body' => 'Done.']));

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 1, 'total_cost_usd' => 1, 'session_id' => 'sess-1']));
            }

            if ($command[0] === 'bash') {
                $this->sequence[] = 'gate';

                if ($this->gateFails && count(array_keys($this->sequence, 'gate', true)) > 1) {
                    return Process::result('FAILED: FooTest', '', 1);
                }
            }

            if (array_slice($command, 0, 3) === ['gh', 'pr', 'create']) {
                $this->prBodies[] = $command[array_search('--body', $command, true) + 1];

                return Process::result("https://github.com/owner/app/pull/12\n");
            }

            return Process::result('');
        });

        $github = Mockery::mock(GitHub::class)->makePartial();
        $github->allows('release');
        $github->allows('labels')->andReturn([]);
        $github->allows('sanitisedIssue')->andReturn('<untrusted_issue>Fix the bug</untrusted_issue>');
        $github->allows('trustedComments')->andReturn('');
        $github->allows('flagForHuman')->andReturnUsing(function ($repo, $issue, $comment) use (&$comments) {
            $comments[] = $comment;
        });

        (new ProcessRun($run))->handle($github);

        return [$run->fresh(), $comments ?? []];
    }

    private function ran(array $prefix): bool
    {
        return collect($this->commands)->contains(fn ($c) => array_slice($c, 0, count($prefix)) === $prefix);
    }

    public function test_the_judge_is_haiku_with_no_tools_and_sees_only_the_issue_and_the_diff(): void
    {
        $this->settle();

        $judge = $this->judgeCommands[0];
        $prompt = $this->judgeInputs[0];

        $this->assertSame(['claude', '-p', '--model', 'haiku'], array_slice($judge, 0, 4));
        $this->assertSame('', $judge[array_search('--tools', $judge, true) + 1]);
        $this->assertSame('project', $judge[array_search('--setting-sources', $judge, true) + 1]);
        $this->assertContains('--strict-mcp-config', $judge);
        $this->assertNotContains('--resume', $judge);
        $this->assertNotContains('--settings', $judge);
        $this->assertStringContainsString('Fix the bug', $prompt);
        $this->assertStringContainsString('+return 1;', $prompt);
    }

    public function test_a_pass_adds_the_verdict_to_the_pr_footer(): void
    {
        [$run] = $this->settle();

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame(['issue', 'gate', 'review', 'judge', 'gate'], $this->sequence);
        $this->assertStringContainsString('Scope judge: passed. Only fixes the bug the issue names.', $this->prBodies[0]);
    }

    public function test_a_veto_gets_one_correction_round_in_the_agents_session_then_the_gate_and_the_judge_again(): void
    {
        [$run] = $this->settle([self::VETO]);

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame(['issue', 'gate', 'review', 'judge', 'correction', 'judge', 'gate'], $this->sequence);

        $correction = collect($this->commands)->first(fn ($c) => $c[0] === 'claude' && str_contains($c[2], 'scope judge'));
        $this->assertStringContainsString('Adds an unrelated settings page.', $correction[2]);
        $this->assertSame('sess-1', $correction[array_search('--resume', $correction, true) + 1]);
        $this->assertStringContainsString('Scope judge: passed after one correction round. Only fixes the bug the issue names.', $this->prBodies[0]);
    }

    public function test_weakened_tests_are_a_veto_even_when_the_change_is_in_scope(): void
    {
        $this->settle([['in_scope' => true, 'weakens_tests' => true, 'reason' => 'Skips ATest.']]);

        $this->assertContains('correction', $this->sequence);
    }

    public function test_a_second_veto_moves_the_run_to_needs_you_with_the_reason(): void
    {
        [$run, $comments] = $this->settle([self::VETO, ['in_scope' => false, 'weakens_tests' => false, 'reason' => 'Still adds the settings page.']]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('scope_vetoed', $run->stop_reason);
        $this->assertNotNull($run->finished_at);
        $this->assertCount(1, $comments);
        $this->assertStringContainsString('Still adds the settings page.', $comments[0]);
        $this->assertFalse($this->git->didPush());
    }

    public function test_an_unusable_answer_is_retried_once(): void
    {
        [$run] = $this->settle([null]);

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame(['issue', 'gate', 'review', 'judge', 'judge', 'gate'], $this->sequence);
        $this->assertStringContainsString('Scope judge: passed.', $this->prBodies[0]);
    }

    public function test_two_unusable_answers_open_the_pr_as_a_draft_labelled_scope_unchecked(): void
    {
        $missingField = json_encode(['type' => 'result', 'is_error' => false, 'structured_output' => ['in_scope' => true, 'reason' => 'Fine.']]);
        [$run, $comments] = $this->settle([null, $missingField]);

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame([], $comments);
        $this->assertSame(['issue', 'gate', 'review', 'judge', 'judge', 'gate'], $this->sequence);
        $this->assertTrue($this->git->didPush());
        $this->assertTrue(collect($this->commands)->contains(fn ($c) => array_slice($c, 0, 3) === ['gh', 'pr', 'create'] && in_array('--draft', $c, true)));
        $this->assertTrue($this->ran(['gh', 'pr', 'edit', '12', '--add-label', 'scope:unchecked']));
        $this->assertStringContainsString('- Scope judge: no usable answer twice, so the change was not checked against the issue. Opened as a draft.', $this->prBodies[0]);
    }

    public function test_two_unusable_answers_with_a_failing_gate_still_move_the_run_to_needs_you(): void
    {
        $this->gateFails = true;
        [$run, $comments] = $this->settle([null, null]);

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertSame('gate_failed', $run->stop_reason);
        $this->assertStringContainsString('FAILED: FooTest', $comments[0]);
        $this->assertFalse($this->git->didPush());
    }

    public function test_an_unusable_answer_after_the_correction_round_also_opens_a_draft(): void
    {
        [$run] = $this->settle([self::VETO, 'not json', 'not json']);

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame(['issue', 'gate', 'review', 'judge', 'correction', 'judge', 'judge', 'gate'], $this->sequence);
        $this->assertTrue($this->ran(['gh', 'pr', 'edit', '12', '--add-label', 'scope:unchecked']));
    }

    public function test_a_passing_judge_opens_a_ready_pr(): void
    {
        $this->settle();

        $this->assertFalse(collect($this->commands)->contains(fn ($c) => in_array('--draft', $c, true) || in_array('scope:unchecked', $c, true)));
    }

    public function test_a_multi_line_reason_is_kept_on_one_line(): void
    {
        [, $comments] = $this->settle([self::VETO, ['in_scope' => false, 'weakens_tests' => false, 'reason' => "Still adds\n\n# the settings page."]]);

        $this->assertStringContainsString('> Out of scope: Still adds # the settings page.', $comments[0]);
    }

    public function test_a_diff_that_fails_stops_the_run_without_asking_the_judge(): void
    {
        $this->diffFails = true;
        [$run, $comments] = $this->settle();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNotContains('judge', $this->sequence);
        $this->assertStringContainsString('fatal: bad revision', $comments[0]);
        $this->assertFalse($this->git->didPush());
    }
}
