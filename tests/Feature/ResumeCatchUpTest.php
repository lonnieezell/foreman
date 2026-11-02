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

/** What the job does around the base merge. How the merge itself goes is in WorktreeTest. */
class ResumeCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    private FakeWorktree $git;

    /** @var list<string>|null What the Worktree had done when the agent started. */
    private ?array $beforeAgent = null;

    /** @var list<string>|null What the Worktree had done when the setup script ran. */
    private ?array $beforeSetup = null;

    /** @var list<list<string>> Commands in the order they ran. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = FakeWorktree::install($this->app);
        $this->worktree = sys_get_temp_dir().'/foreman-catchup-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    private function makeRun(array $attributes = []): Run
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true', 'base_branch' => 'develop',
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
            ...$attributes,
        ]);
    }

    private function process(Run $run, string $comments = ''): Run
    {
        Process::fake(function (PendingProcess $process) {
            $this->ran[] = $process->command;

            if ($process->command[0] === 'claude') {
                $this->beforeAgent ??= $this->git->calls;
            }

            if ($process->command === ['bash', '-ec', 'true']) {
                $this->beforeSetup = $this->git->calls;
            }

            return Process::result(json_encode(['type' => 'result', 'num_turns' => 1]));
        });

        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('trustedComments')->andReturn($comments);

        (new ProcessRun($run))->handle($github);

        return $run->fresh();
    }

    /** The prompt the agent was started with. */
    private function prompt(): string
    {
        $claude = collect($this->ran)->first(fn (array $command) => $command[0] === 'claude');

        return $claude[array_search('-p', $claude, true) + 1];
    }

    public function test_a_resumed_run_catches_up_with_the_base_after_fetching_before_the_agent_starts(): void
    {
        $this->process($this->makeRun());

        $this->assertSame(['catchUp:fetch', 'excludeFromGit'], array_slice($this->beforeAgent, 0, 2));
        $this->assertSame('develop', $this->git->builtWith['base']);
    }

    public function test_a_fix_up_round_catches_up_with_the_base_too(): void
    {
        $this->process($this->makeRun(['pr_round' => 1, 'pr_url' => 'https://github.com/owner/app/pull/3']));

        $this->assertContains('catchUp:fetch', $this->beforeAgent);
    }

    public function test_a_rebuilt_worktree_catches_up_before_the_setup_script_without_fetching_again(): void
    {
        $this->git->pushed = true;

        $this->process($this->makeRun(['worktree_path' => "{$this->worktree}/gone"]));

        $this->assertSame(['prepare', 'catchUp'], $this->beforeSetup, 'dependencies the base adds get installed');
    }

    public function test_a_rebuilt_worktree_that_was_never_pushed_does_not_catch_up(): void
    {
        $this->process($this->makeRun(['worktree_path' => "{$this->worktree}/gone"]));

        $this->assertSame(['prepare'], $this->beforeSetup);
    }

    public function test_a_first_run_does_not_catch_up(): void
    {
        $this->process($this->makeRun(['resume_session_id' => null, 'worktree_path' => null]));

        $this->assertNotContains('catchUp', $this->beforeAgent);
        $this->assertNotContains('catchUp:fetch', $this->beforeAgent);
    }

    public function test_the_resume_prompt_says_the_result_file_was_cleared(): void
    {
        $this->process($this->makeRun(), comments: "owner wrote:\nGo ahead.");

        $this->assertStringContainsString(ProcessRun::RESULT_FILE.' was cleared', $this->prompt());
    }

    public function test_the_resume_prompt_lists_the_commits_merged_from_base(): void
    {
        $this->git->merged = ['fix(tests): clear inherited agent git config', 'feat(#9): add a thing'];

        $this->process($this->makeRun());

        $prompt = $this->prompt();

        $this->assertStringContainsString('merged origin/develop into your branch', $prompt);
        $this->assertStringContainsString('fix(tests): clear inherited agent git config', $prompt);
        $this->assertStringContainsString('feat(#9): add a thing', $prompt);
        $this->assertStringContainsString('run your checks again', $prompt);
    }

    public function test_the_resume_prompt_lists_no_commits_when_the_merge_brought_none(): void
    {
        $this->process($this->makeRun());

        $this->assertStringNotContainsString('into your branch', $this->prompt());
    }

    public function test_a_resume_without_new_comments_says_it_was_continued_by_hand(): void
    {
        $this->process($this->makeRun());

        $this->assertStringNotContainsString('A human has replied', $this->prompt());
        $this->assertStringContainsString('continued this run by hand', $this->prompt());
    }

    public function test_a_resume_with_new_comments_shows_them(): void
    {
        $this->process($this->makeRun(), comments: "owner wrote:\nGo ahead.");

        $this->assertStringContainsString('A human has replied on issue #7', $this->prompt());
        $this->assertStringContainsString('Go ahead.', $this->prompt());
    }
}
