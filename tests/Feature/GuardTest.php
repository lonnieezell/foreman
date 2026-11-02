<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Guardrails\Guard;
use App\Guardrails\StackSet;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GuardTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-guard-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/work");
        file_put_contents("{$this->dir}/work/composer.json", '{}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** Pipes a hook call into the real command. @return array{int, string} exit code and stderr */
    private function hook(array $call, array $rules = []): array
    {
        file_put_contents("{$this->dir}/rules.json", json_encode([
            'worktree' => "{$this->dir}/work",
            'log' => "{$this->dir}/run.log",
            'locked_paths' => [],
            'allow_lock_files' => false,
            ...$rules,
        ]));

        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:guard', "{$this->dir}/rules.json"], base_path());
        $process->setInput(json_encode($call));
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput()];
    }

    private function edit(string $path, array $input = [], string $tool = 'Edit'): array
    {
        return ['tool_name' => $tool, 'tool_input' => ['file_path' => "{$this->dir}/work/{$path}", ...$input]];
    }

    private function bash(string $command): array
    {
        return ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
    }

    public static function blockedCalls(): array
    {
        return [
            'stack config' => ['edit', 'phpunit.xml', [], 'tool settings'],
            'nested stack config' => ['edit', 'sub/phpstan.neon', [], 'tool settings'],
            'ci workflow' => ['edit', '.github/workflows/ci.yml', [], 'protected'],
            'CLAUDE.md' => ['edit', 'CLAUDE.md', [], 'protected'],
            'AGENTS.md' => ['edit', 'AGENTS.md', [], 'protected'],
            '.claude folder' => ['edit', '.claude/settings.json', [], 'protected'],
            '.env' => ['edit', '.env', [], 'protected'],
            '.env.local' => ['edit', '.env.local', [], 'protected'],
            'lock file' => ['edit', 'composer.lock', [], 'lock file'],
            'write to a lock file' => ['edit', 'composer.lock', ['content' => '{}'], 'lock file'],
            'skip marker in an edit' => ['edit', 'tests/FooTest.php', ['old_string' => 'a', 'new_string' => '$this->markTestSkipped();'], 'skip'],
            'skip marker in a write' => ['edit', 'tests/FooTest.php', ['content' => "<?php\n// @phpstan-ignore-next-line"], 'skip'],
            'git push' => ['bash', 'git push -u origin agent/issue-1', null, 'git push'],
            'git push after another command' => ['bash', 'composer test && git push', null, 'git push'],
            'git push with options first' => ['bash', 'git -C . push', null, 'git push'],
            'gh pr create' => ['bash', 'gh pr create --title x', null, 'gh commands'],
            'gh issue view' => ['bash', 'gh issue view 1', null, 'gh commands'],
            'gh after a pipe' => ['bash', 'echo hi | gh api user', null, 'gh commands'],
            '--no-verify' => ['bash', 'git commit --no-verify -m x', null, '--no-verify'],
        ];
    }

    #[DataProvider('blockedCalls')]
    public function test_blocks_with_exit_2_and_a_reason(string $kind, string $subject, ?array $input, string $reason): void
    {
        $call = $kind === 'edit' ? $this->edit($subject, $input) : $this->bash($subject);

        [$code, $stderr] = $this->hook($call);

        $this->assertSame(2, $code, $stderr);
        $this->assertStringContainsString($reason, $stderr);
    }

    public function test_multi_edit_with_a_skip_marker_is_blocked(): void
    {
        $call = $this->edit('tests/FooTest.php', ['edits' => [
            ['old_string' => 'a', 'new_string' => 'b'],
            ['old_string' => 'c', 'new_string' => '$this->markTestSkipped();'],
        ]], 'MultiEdit');

        $this->assertSame(2, $this->hook($call)[0]);
    }

    public static function allowedCalls(): array
    {
        return [
            'source file' => ['edit', 'app/Foo.php', ['old_string' => 'a', 'new_string' => 'b']],
            'new test file' => ['edit', 'tests/FooTest.php', ['content' => '<?php // a test']],
            'existing test edit' => ['edit', 'tests/FooTest.php', ['old_string' => 'a', 'new_string' => 'b']],
            'a skip marker that was already there' => ['edit', 'tests/FooTest.php', ['old_string' => '->skip(', 'new_string' => '->skip( // why']],
            '.env.example' => ['edit', '.env.example', ['content' => 'APP=x']],
            'file outside the worktree' => ['absolute', '/tmp/elsewhere/phpunit.xml', []],
            'git commit' => ['bash', 'git commit -m "fix: gh and push words in a message"', null],
            'git status' => ['bash', 'git status', null],
            'composer test' => ['bash', 'composer test', null],
            'a word ending in gh' => ['bash', 'echo high', null],
        ];
    }

    #[DataProvider('allowedCalls')]
    public function test_allows_everything_else_with_exit_0(string $kind, string $subject, ?array $input): void
    {
        $call = match ($kind) {
            'edit' => $this->edit($subject, $input),
            'absolute' => ['tool_name' => 'Edit', 'tool_input' => ['file_path' => $subject]],
            default => $this->bash($subject),
        };

        [$code, $stderr] = $this->hook($call);

        $this->assertSame(0, $code, $stderr);
    }

    public function test_tools_the_guard_does_not_watch_pass(): void
    {
        $this->assertSame(0, $this->hook(['tool_name' => 'Read', 'tool_input' => ['file_path' => "{$this->dir}/work/.env"]])[0]);
    }

    public function test_lock_files_pass_when_the_issue_has_the_dependencies_label(): void
    {
        $this->assertSame(0, $this->hook($this->edit('composer.lock'), ['allow_lock_files' => true])[0]);
    }

    public function test_locked_paths_from_the_repository_are_enforced(): void
    {
        $rules = ['locked_paths' => ['database/migrations/**', 'tests/Feature/BillingTest.php']];

        $this->assertSame(2, $this->hook($this->edit('database/migrations/2026_create_x.php'), $rules)[0]);
        $this->assertSame(2, $this->hook($this->edit('tests/Feature/BillingTest.php'), $rules)[0]);
        $this->assertSame(0, $this->hook($this->edit('tests/Feature/OtherTest.php'), $rules)[0]);
        $this->assertSame(0, $this->hook($this->edit('sub/tests/Feature/BillingTest.php'), $rules)[0], 'locked paths are anchored at the repository root');
    }

    public function test_foremans_hook_files_are_off_limits_to_edit_tools_and_the_shell(): void
    {
        $rules = ['hooks_dir' => "{$this->dir}/hooks"];
        $file = "{$this->dir}/hooks/run-1-rules.json";

        foreach (['Edit', 'Write'] as $tool) {
            [$code, $stderr] = $this->hook(['tool_name' => $tool, 'tool_input' => ['file_path' => $file, 'content' => '{}']], $rules);
            $this->assertSame(2, $code);
            $this->assertStringContainsString('hook files', $stderr);
        }

        $this->assertSame(2, $this->hook($this->bash("echo 3 > {$this->dir}/hooks/run-1-stops"), $rules)[0]);
        $this->assertSame(0, $this->hook($this->bash('git status'), $rules)[0]);
    }

    public function test_the_gate_check_finds_locked_paths_and_lock_files_in_a_list_of_changed_files(): void
    {
        $guard = new Guard(StackSet::detect("{$this->dir}/work"), "{$this->dir}/work", ['database/**']);
        $changes = $guard->lockedChanges(['app/Foo.php', 'database/migrations/x.php', 'composer.lock']);

        $this->assertCount(2, $changes);
        $this->assertStringContainsString('database/migrations/x.php is locked', $changes[0]);
        $this->assertStringContainsString('composer.lock is a lock file', $changes[1]);

        $allowed = new Guard(StackSet::detect("{$this->dir}/work"), "{$this->dir}/work", [], true);
        $this->assertSame([], $allowed->lockedChanges(['composer.lock']));
    }

    public function test_a_relative_path_is_checked_too(): void
    {
        $this->assertSame(2, $this->hook(['tool_name' => 'Edit', 'tool_input' => ['file_path' => './CLAUDE.md']])[0]);
    }

    public function test_blocks_are_written_to_the_run_log(): void
    {
        $this->hook($this->bash('git push'));

        $this->assertStringContainsString('Blocked by guardrail: git push is blocked', file_get_contents("{$this->dir}/run.log"));
    }

    public function test_allowed_calls_leave_the_log_alone(): void
    {
        $this->hook($this->bash('git status'));

        $this->assertFileDoesNotExist("{$this->dir}/run.log");
    }

    public function test_a_broken_hook_fails_closed_with_exit_2(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:guard', "{$this->dir}/missing.json"], base_path());
        $process->setInput('not json');
        $process->run();

        $this->assertSame(2, $process->getExitCode());
    }

    public function test_every_run_starts_claude_with_a_settings_file_outside_the_worktree(): void
    {
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'locked_paths' => "database/**\n\n tests/Locked.php ",
        ]);
        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            'worktree_path' => "{$this->dir}/work",
        ]);

        ProcessFacade::fake(['*' => ProcessFacade::result(json_encode(['type' => 'result', 'num_turns' => 1]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn(['dependencies']);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        $command = null;
        ProcessFacade::assertRan(function (PendingProcess $p) use (&$command) {
            if ($p->command[0] === 'claude') {
                $command = $p->command;
            }

            return $command !== null;
        });

        $settings = $command[array_search('--settings', $command) + 1];
        $this->assertFileExists($settings);
        $this->assertStringStartsNotWith("{$this->dir}/work", $settings);

        $config = json_decode(file_get_contents($settings), true);
        $hook = $config['hooks']['PreToolUse'][0];
        $this->assertStringContainsString('Bash', $hook['matcher']);
        $this->assertStringContainsString('foreman:guard', $hook['hooks'][0]['command']);
        $this->assertStringContainsString('foreman:format-staged', $hook['hooks'][1]['command']);

        $rules = json_decode(file_get_contents(str_replace('-settings.json', '-rules.json', $settings)), true);
        $this->assertSame(['database/**', 'tests/Locked.php'], $rules['locked_paths']);
        $this->assertTrue($rules['allow_lock_files']);
        $this->assertSame(dirname($settings), $rules['hooks_dir']);
        $this->assertSame($run->logPath(), $rules['log']);

        File::delete([$settings, str_replace('-settings.json', '-rules.json', $settings)]);
    }

    public function test_locked_paths_can_be_saved_in_the_repository_settings(): void
    {
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => 'Read', 'setup_script' => 'true', 'base_branch' => 'main',
            'ready_label' => 'ready-for-agent', 'working_label' => 'agent-working', 'human_label' => 'needs-human',
            'cleanup_worktrees' => true, 'watch_prs' => true,
            'model' => 'sonnet', 'concurrency' => 1, 'poll_interval_minutes' => 10, 'max_turns' => 40, 'max_minutes' => 45,
        ]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('locked_paths', "database/migrations/**\ntests/Feature/BillingTest.php")
            ->call('save', $github)
            ->assertHasNoErrors();

        $this->assertSame(['database/migrations/**', 'tests/Feature/BillingTest.php'], $repo->fresh()->lockedPathList());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('locked_paths', "database/migrations/**\ntests/Feature/BillingTest.php");
    }
}
