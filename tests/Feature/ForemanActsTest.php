<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForemanActsTest extends TestCase
{
    use RefreshDatabase;

    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->worktree = sys_get_temp_dir().'/foreman-acts-'.uniqid();
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
            ...$repository,
        ])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => $this->worktree,
        ]);
    }

    /** Fakes every process. When the agent starts, it writes the given result file, or none for null. */
    private function fakeAgent(string|array|null $result, string $lastMessage = ''): void
    {
        Process::fake(function (PendingProcess $process) use ($result, $lastMessage) {
            $command = $process->command;

            if ($command[0] === 'claude') {
                if ($result !== null) {
                    File::ensureDirectoryExists("{$this->worktree}/.foreman");
                    File::put("{$this->worktree}/".ProcessRun::RESULT_FILE, is_array($result) ? json_encode($result) : $result);
                }

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 3, 'result' => $lastMessage]));
            }

            if (array_slice($command, 0, 3) === ['gh', 'pr', 'create']) {
                return Process::result("https://github.com/owner/app/pull/12\n");
            }

            return Process::result('');
        });
    }

    private function settle(Run $run): Run
    {
        $github = Mockery::mock(GitHub::class)->makePartial();
        $github->allows('release');
        $github->allows('pullRequest')->andReturn(['head_sha' => 'abc1234']);

        (new ProcessRun($run))->handle($github);

        return $run->fresh();
    }

    private function assertRanCommand(array $prefix): void
    {
        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, count($prefix)) === $prefix);
    }

    private function assertDidntRunCommand(array $prefix): void
    {
        Process::assertDidntRun(fn (PendingProcess $p) => array_slice($p->command, 0, count($prefix)) === $prefix);
    }

    public function test_a_pr_result_pushes_the_branch_and_opens_the_pr(): void
    {
        $this->fakeAgent(['outcome' => 'pr', 'title' => 'Add the thing', 'body' => 'It adds the thing.']);

        $run = $this->settle($this->makeRun(['watch_prs' => false]));

        $this->assertSame(RunStatus::PrOpened, $run->status);
        $this->assertSame('https://github.com/owner/app/pull/12', $run->pr_url);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'push', '--set-upstream', 'origin', 'agent/issue-7']
            && $p->path === $this->worktree
            && ! array_key_exists('GIT_CONFIG_COUNT', $p->environment));
        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'pr', 'create']
            && in_array('Add the thing', $p->command, true)
            && collect($p->command)->contains(fn ($arg) => str_starts_with($arg, "Closes #7\n\nIt adds the thing.\n\n---\n**Foreman's gate**")));
    }

    public function test_a_pr_that_is_watched_moves_to_watching(): void
    {
        $this->fakeAgent(['outcome' => 'pr', 'title' => 'T', 'body' => "Closes #7\n\nDone."]);

        $run = $this->settle($this->makeRun());

        $this->assertSame(RunStatus::WatchingPr, $run->status);
        $this->assertSame('abc1234', $run->head_sha);
        Process::assertRan(fn (PendingProcess $p) => collect($p->command)->contains(fn ($arg) => str_starts_with($arg, "Closes #7\n\nDone.\n\n---\n**Foreman's gate**")));
    }

    public function test_needs_human_posts_the_comment_and_adds_the_label_without_pushing(): void
    {
        $this->fakeAgent(['outcome' => 'needs_human', 'reason' => 'test_looks_wrong', 'comment' => 'FooTest asserts the wrong thing.']);

        $run = $this->settle($this->makeRun());

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertRanCommand(['gh', 'issue', 'comment', '7', '--body', 'FooTest asserts the wrong thing.']);
        Process::assertRan(fn (PendingProcess $p) => in_array('--add-label', $p->command, true) && in_array('needs-human', $p->command, true));
        $this->assertDidntRunCommand(['git', 'push']);
        $this->assertDidntRunCommand(['gh', 'pr', 'create']);
        $this->assertStringContainsString('test_looks_wrong', $run->tail());
    }

    public function test_already_done_posts_the_comment_and_adds_the_label(): void
    {
        $this->fakeAgent(['outcome' => 'already_done', 'comment' => 'Done in abc123.']);

        $run = $this->settle($this->makeRun());

        $this->assertSame(RunStatus::NeedsHuman, $run->status);
        $this->assertRanCommand(['gh', 'issue', 'comment', '7', '--body', 'Done in abc123.']);
        $this->assertDidntRunCommand(['git', 'push']);
    }

    public static function badResults(): array
    {
        return [
            'missing file' => [null],
            'not json' => ['{oops'],
            'unknown outcome' => [['outcome' => 'merged']],
            'pr without a body' => [['outcome' => 'pr', 'title' => 'T']],
            'needs_human without a reason' => [['outcome' => 'needs_human', 'comment' => 'c']],
            'already_done with a blank comment' => [['outcome' => 'already_done', 'comment' => ' ']],
        ];
    }

    #[DataProvider('badResults')]
    public function test_a_missing_or_malformed_result_fails_the_run_and_posts_the_last_message(string|array|null $result): void
    {
        $this->fakeAgent($result, 'I got stuck on the migration.');

        $run = $this->settle($this->makeRun());

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('without a readable result file', $run->error);
        $this->assertStringContainsString('I got stuck on the migration.', $run->error);
        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'issue', 'comment']
            && str_contains($p->command[5], '> I got stuck on the migration.'));
        $this->assertDidntRunCommand(['git', 'push']);
    }

    public function test_a_result_file_left_by_an_earlier_run_is_ignored(): void
    {
        File::ensureDirectoryExists("{$this->worktree}/.foreman");
        File::put("{$this->worktree}/".ProcessRun::RESULT_FILE, json_encode(['outcome' => 'already_done', 'comment' => 'stale']));
        $this->fakeAgent(null);

        $run = $this->settle($this->makeRun());

        $this->assertSame(RunStatus::Failed, $run->status);
    }

    public function test_the_agent_runs_without_push_rights_and_in_dont_ask_mode(): void
    {
        $this->fakeAgent(['outcome' => 'already_done', 'comment' => 'c']);

        $this->settle($this->makeRun());

        Process::assertRan(function (PendingProcess $p) {
            $command = $p->command;

            return $command[0] === 'claude'
                && $command[array_search('--permission-mode', $command) + 1] === 'dontAsk'
                && $p->environment['GH_TOKEN'] === false
                && $p->environment['GITHUB_TOKEN'] === false;
        });
    }

    public function test_git_push_fails_in_the_agents_environment_and_works_in_foremans(): void
    {
        $origin = "{$this->worktree}/origin.git";
        $clone = "{$this->worktree}/clone";
        $git = fn (string $cwd, array $args, array $env = []) => Process::path($cwd)->env($env)->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]);

        $git($this->worktree, ['init', '--bare', '--initial-branch=main', $origin])->throw();
        $git($this->worktree, ['clone', $origin, $clone])->throw();
        $git($clone, ['commit', '--allow-empty', '-m', 'first'])->throw();

        $agent = $git($clone, ['push', 'origin', 'HEAD:main'], ProcessRun::agentEnv());
        $this->assertTrue($agent->failed());
        $this->assertSame('', trim($git($origin, ['branch', '--list'])->output()));

        $this->assertTrue($git($clone, ['push', 'origin', 'HEAD:main'])->successful());
        $this->assertStringContainsString('main', $git($origin, ['branch', '--list'])->output());
    }

    public function test_the_default_tools_drop_gh_and_blanket_git_and_add_rust_and_python(): void
    {
        $tools = explode(',', Repository::DEFAULT_ALLOWED_TOOLS);

        foreach (['Bash(git:*)', 'Bash(gh issue:*)', 'Bash(gh pr create:*)'] as $old) {
            $this->assertNotContains($old, $tools);
        }
        foreach (['Bash(git status:*)', 'Bash(git diff:*)', 'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(cargo:*)', 'Bash(pytest:*)', 'Bash(ruff:*)', 'Bash(uv:*)'] as $new) {
            $this->assertContains($new, $tools);
        }
        $this->assertNotContains('Bash(git push:*)', $tools);
    }

    public function test_the_settings_page_suggests_restoring_the_default_when_the_list_is_old(): void
    {
        $old = 'Read,Edit,Bash(git:*),Bash(gh issue:*),Bash(gh pr create:*)';
        $repository = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => $old, 'setup_script' => 'true',
        ])->fresh();

        Livewire::test(RepositoryForm::class, ['repository' => $repository])
            ->assertSee('still lets the agent push or use')
            ->call('resetAllowedTools')
            ->assertSet('allowed_tools', Repository::DEFAULT_ALLOWED_TOOLS)
            ->assertDontSee('still lets the agent push or use');

        $repository->update(['allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS]);
        Livewire::test(RepositoryForm::class, ['repository' => $repository])->assertDontSee('still lets the agent push or use');
    }

    public function test_the_default_prompt_tells_the_agent_to_commit_write_the_result_and_use_test_looks_wrong(): void
    {
        $prompt = file_get_contents(resource_path('prompts/default.md'));

        $this->assertStringContainsString('commit', $prompt);
        $this->assertStringContainsString('.foreman/result.json', $prompt);
        $this->assertStringContainsString('test_looks_wrong', $prompt);
        $this->assertStringNotContainsString('git push', $prompt);
        $this->assertStringNotContainsString('gh pr create', $prompt);
    }
}
