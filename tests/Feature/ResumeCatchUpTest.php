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
use RuntimeException;
use Tests\TestCase;

class ResumeCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    /** @var list<list<string>> Commands in the order they ran. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * Runs the job with git answering as given: `status` is the porcelain output, `merge` and `fetch` the exit codes.
     * A `merge` of -1 throws, as a timeout does.
     */
    private function process(Run $run, string $status = '', int $merge = 0, int $fetch = 0): Run
    {
        Process::fake(function (PendingProcess $process) use ($status, $merge, $fetch) {
            $this->ran[] = $process->command;
            $abort = $process->command === ['git', 'merge', '--abort'];

            if ($merge === -1 && ! $abort && array_slice($process->command, 0, 2) === ['git', 'merge']) {
                throw new RuntimeException('The process "git merge" exceeded the timeout of 300 seconds.');
            }

            return match (array_slice($process->command, 0, 2)) {
                ['git', 'status'] => Process::result($status),
                ['git', 'fetch'] => Process::result('', $fetch ? 'fatal: unable to access origin' : '', $fetch),
                ['git', 'merge'] => Process::result('', $abort ? '' : 'CONFLICT (content): Merge conflict in app/A.php', $abort ? 0 : $merge),
                default => Process::result(json_encode(['type' => 'result', 'num_turns' => 1])),
            };
        });

        (new ProcessRun($run))->handle(Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        return $run->fresh();
    }

    /** Where `$command` ran, by position, or null when it never ran. */
    private function position(array $command): ?int
    {
        $found = array_search($command, $this->ran, true);

        return $found === false ? null : $found;
    }

    private function claudePosition(): ?int
    {
        foreach ($this->ran as $i => $command) {
            if ($command[0] === 'claude') {
                return $i;
            }
        }

        return null;
    }

    public function test_a_resumed_run_merges_the_latest_base_before_the_agent_starts(): void
    {
        $this->process($this->makeRun());

        $fetch = $this->position(['git', 'fetch', 'origin', '--quiet']);
        $merge = $this->position(['git', 'merge', '--no-edit', 'origin/develop']);

        $this->assertNotNull($fetch);
        $this->assertNotNull($merge);
        $this->assertLessThan($merge, $fetch);
        $this->assertLessThan($this->claudePosition(), $merge);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'merge', '--no-edit', 'origin/develop'] && $p->path === $this->worktree);
    }

    public function test_a_fix_up_round_merges_the_latest_base_too(): void
    {
        $this->process($this->makeRun(['pr_round' => 1, 'pr_url' => 'https://github.com/owner/app/pull/3']));

        $merge = $this->position(['git', 'merge', '--no-edit', 'origin/develop']);

        $this->assertNotNull($merge);
        $this->assertLessThan($this->claudePosition(), $merge);
    }

    public function test_a_conflict_is_aborted_and_the_run_goes_on_from_the_branch_as_it_was(): void
    {
        $run = $this->process($this->makeRun(), merge: 1);

        $abort = $this->position(['git', 'merge', '--abort']);

        $this->assertNotNull($abort);
        $this->assertLessThan($this->claudePosition(), $abort, 'the agent still starts, after the abort');
        $this->assertStringContainsString("Couldn't merge origin/develop", (string) file_get_contents($run->logPath()));
        $this->assertStringContainsString('Merge conflict in app/A.php', (string) file_get_contents($run->logPath()));
    }

    public function test_uncommitted_changes_skip_the_merge_so_they_are_never_put_at_risk(): void
    {
        $run = $this->process($this->makeRun(), status: " M app/A.php\n");

        $this->assertNull($this->position(['git', 'merge', '--no-edit', 'origin/develop']));
        $this->assertNotNull($this->claudePosition());
        $this->assertStringContainsString('uncommitted changes', (string) file_get_contents($run->logPath()));
    }

    public function test_a_failed_fetch_skips_the_merge_and_the_run_goes_on(): void
    {
        $run = $this->process($this->makeRun(), fetch: 1);

        $this->assertNull($this->position(['git', 'merge', '--no-edit', 'origin/develop']));
        $this->assertNotNull($this->claudePosition());
        $this->assertStringContainsString("Couldn't fetch origin", (string) file_get_contents($run->logPath()));
    }

    public function test_a_merge_that_throws_is_aborted_and_the_run_goes_on(): void
    {
        $run = $this->process($this->makeRun(), merge: -1);

        $this->assertNotNull($this->position(['git', 'merge', '--abort']));
        $this->assertNotNull($this->claudePosition());
        $this->assertStringContainsString('exceeded the timeout', (string) file_get_contents($run->logPath()));
    }

    public function test_a_rebuilt_worktree_merges_before_the_setup_script_and_fetches_once(): void
    {
        $this->process($this->makeRun(['worktree_path' => "{$this->worktree}/gone"]));

        $merge = $this->position(['git', 'merge', '--no-edit', 'origin/develop']);
        $setup = $this->position(['bash', '-ec', 'true']);

        $this->assertNotNull($merge);
        $this->assertNotNull($setup);
        $this->assertLessThan($setup, $merge, 'dependencies the base adds get installed');
        $this->assertCount(1, array_filter($this->ran, fn ($c) => array_slice($c, 0, 2) === ['git', 'fetch']));
    }

    public function test_a_first_run_does_not_merge(): void
    {
        $this->process($this->makeRun(['resume_session_id' => null, 'worktree_path' => null]));

        $this->assertNull($this->position(['git', 'merge', '--no-edit', 'origin/develop']));
    }
}
