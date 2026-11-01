<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Guardrails\Sandbox;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-sandbox-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_sandbox_is_off_by_default(): void
    {
        $repo = $this->repository();
        [$settings, $env] = $this->startClaude($repo);

        $this->assertFalse($repo->fresh()->sandbox);
        $this->assertArrayNotHasKey('sandbox', $settings);
        $this->assertArrayNotHasKey('npm_config_cache', $env, 'an unsandboxed run uses your own caches');
        $this->assertSame('2', $env['GIT_CONFIG_COUNT'], 'and signs its own commits');
    }

    public function test_a_sandboxed_run_starts_claude_in_the_sandbox_with_foremans_caches_and_no_signing(): void
    {
        [$settings, $env] = $this->startClaude($this->repository(['sandbox' => true]));

        $this->assertTrue($settings['sandbox']['enabled']);
        $this->assertSame(storage_path('foreman/cache/npm'), $env['npm_config_cache']);
        $this->assertSame(storage_path('foreman/cache/composer'), $env['COMPOSER_CACHE_DIR']);
        $this->assertSame('3', $env['GIT_CONFIG_COUNT']);
        $this->assertSame('commit.gpgsign', $env['GIT_CONFIG_KEY_2']);
        $this->assertSame('false', $env['GIT_CONFIG_VALUE_2']);
        $this->assertSame('remote.origin.pushurl', $env['GIT_CONFIG_KEY_0'], 'the push block stays');
    }

    /** Built against a real worktree, since the paths come from git. */
    public function test_the_sandbox_lets_commits_through_but_not_what_foremans_own_git_commands_would_run(): void
    {
        $repo = "{$this->dir}/repo";
        $worktree = "{$this->dir}/wt";
        File::ensureDirectoryExists($repo);
        file_put_contents("{$repo}/composer.json", '{}');
        foreach ([['init', '-q', '-b', 'main'], ['add', '-A'], ['-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'init'], ['worktree', 'add', '-q', '-b', 'agent/issue-1', $worktree]] as $args) {
            Process::path($repo)->run(['git', ...$args])->throw();
        }
        $repo = realpath($repo);
        $worktree = realpath($worktree);

        $sandbox = Sandbox::settings($worktree);
        $writes = $sandbox['filesystem']['allowWrite'];

        foreach (['objects', 'refs', 'logs', 'packed-refs', 'worktrees/wt'] as $path) {
            $this->assertContains("{$repo}/.git/{$path}", $writes, "commits write {$path}");
        }
        $this->assertNotContains("{$repo}/.git", $writes, 'not the hooks folder or the config');
        $this->assertContains(storage_path('foreman/cache'), $writes);
        $this->assertContains("{$worktree}/.git", $sandbox['filesystem']['denyWrite'], 'the pointer to the git folder');
        $this->assertContains("{$repo}/.git/worktrees/wt/commondir", $sandbox['filesystem']['denyWrite']);
        $this->assertContains(getenv('HOME').'/.gnupg', $sandbox['filesystem']['denyRead']);

        $this->assertTrue($sandbox['failIfUnavailable']);
        $this->assertFalse($sandbox['allowUnsandboxedCommands']);
        $this->assertContains('github.com', $sandbox['network']['allowedDomains']);
        $this->assertContains('packagist.org', $sandbox['network']['allowedDomains']);
        $this->assertNotContains('registry.npmjs.org', $sandbox['network']['allowedDomains'], 'only the detected stacks get hosts');
    }

    public function test_the_sandbox_can_be_turned_on_in_the_repository_settings(): void
    {
        $repo = $this->repository([
            'local_path' => base_path(), 'base_branch' => 'main', 'ready_label' => 'ready-for-agent',
            'working_label' => 'agent-working', 'human_label' => 'needs-human', 'model' => 'sonnet',
            'concurrency' => 1, 'poll_interval_minutes' => 10, 'max_turns' => 40, 'max_minutes' => 45,
            'cleanup_worktrees' => true, 'watch_prs' => true,
        ]);

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('sandbox', true)
            ->call('save', Mockery::mock(GitHub::class)->shouldIgnoreMissing())
            ->assertHasNoErrors();

        $this->assertTrue($repo->fresh()->sandbox);
        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])->assertSet('sandbox', true);
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => "{$this->dir}/repo", 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    /**
     * Run the job with faked processes.
     *
     * @return array{0:array,1:array} The settings file Claude was started with, and its environment.
     */
    private function startClaude(Repository $repo): array
    {
        File::ensureDirectoryExists($repo->worktreePath(7));
        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
        ]);

        $claude = null;
        Process::fake(function (PendingProcess $p) use (&$claude) {
            if ($p->command[0] === 'claude') {
                $claude = $p;

                return Process::result(json_encode(['type' => 'result', 'num_turns' => 1]));
            }

            return in_array('--git-common-dir', $p->command, true)
                ? Process::result("{$this->dir}/repo/.git/worktrees/issue-7\n{$this->dir}/repo/.git\n")
                : Process::result('');
        });
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        $settings = $claude->command[array_search('--settings', $claude->command) + 1];
        $config = json_decode(file_get_contents($settings), true);
        File::delete([$settings, str_replace('-settings.json', '-rules.json', $settings)]);
        File::deleteDirectory($repo->worktreePath(7));

        return [$config, $claude->environment];
    }
}
