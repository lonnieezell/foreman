<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Models\Setting;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ClaudeProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['factory.claude_profiles' => ['work' => '/home/me/.claude-work']]);
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => true,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    public function test_profiles_are_parsed_from_the_environment(): void
    {
        // env() reads $_SERVER first, so this overrides whatever the local .env sets
        $original = $_SERVER['CLAUDE_PROFILES'] ?? null;
        $_SERVER['CLAUDE_PROFILES'] = 'personal:~/.claude-personal, work:/opt/claude-work,broken';
        $config = require config_path('factory.php');
        $_SERVER['CLAUDE_PROFILES'] = $original;

        $this->assertSame([
            'personal' => getenv('HOME').'/.claude-personal',
            'work' => '/opt/claude-work',
        ], $config['claude_profiles']);
    }

    /** Runs the job against a faked `claude` that prints the given result event. */
    private function processRun(Repository $repo, array $result): void
    {
        $run = $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            'resume_session_id' => 'abc', 'worktree_path' => sys_get_temp_dir(),
        ]);

        Process::fake(['*' => Process::result(json_encode(['type' => 'result', ...$result]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        File::delete([$run->logPath(), $run->rawLogPath()]);
    }

    public function test_claude_runs_with_the_repository_profile(): void
    {
        $this->processRun($this->repository(['claude_profile' => 'work']), ['num_turns' => 1]);

        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'claude'
            && $process->environment === ['CLAUDE_CONFIG_DIR' => '/home/me/.claude-work']);
    }

    public function test_usage_limit_pauses_only_that_profile(): void
    {
        $this->processRun($this->repository(['claude_profile' => 'work']), [
            'is_error' => true, 'subtype' => 'error', 'result' => 'Claude AI usage limit reached',
        ]);

        $this->assertTrue(Setting::profilePaused('work'));
        $this->assertFalse(Setting::profilePaused('default'));
        $this->assertFalse(Setting::paused());
    }

    public function test_repositories_without_a_profile_use_the_default_login(): void
    {
        $this->assertSame([], $this->repository()->claudeEnv());
        $this->assertSame('default', $this->repository()->profileName());
    }

    public function test_poller_skips_repositories_on_a_paused_profile(): void
    {
        $this->repository(['claude_profile' => 'work']);
        $other = $this->repository(['name' => 'other', 'github_repo' => 'owner/other']);
        Setting::pauseProfile('work', 'Out of usage.');

        $github = Mockery::mock(GitHub::class);
        $github->expects('readyIssues')->once()->withArgs(fn ($repo) => $repo->is($other))->andReturn([]);
        $this->app->instance(GitHub::class, $github);

        $this->artisan('factory:poll')->assertSuccessful();
    }

    public function test_paused_profiles_can_be_resumed(): void
    {
        Setting::pauseProfile('work', 'Out of usage.');
        $this->assertSame(['work' => 'Out of usage.'], Setting::pausedProfiles());

        Setting::resumeProfile('work');

        $this->assertFalse(Setting::profilePaused('work'));
        $this->assertSame([], Setting::pausedProfiles());
    }

    public function test_form_saves_a_known_profile_and_rejects_unknown_ones(): void
    {
        $repo = $this->repository();
        $this->app->instance(GitHub::class, Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('claude_profile', 'work')    // no default option once profiles are defined
            ->assertDontSee('Default (~/.claude)')
            ->set('claude_profile', '')
            ->call('save')
            ->assertHasErrors('claude_profile')
            ->set('claude_profile', 'nope')
            ->call('save')
            ->assertHasErrors('claude_profile')
            ->set('claude_profile', 'work')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('work', $repo->fresh()->claude_profile);
    }

    public function test_form_offers_the_default_login_when_no_profiles_are_defined(): void
    {
        config(['factory.claude_profiles' => []]);

        Livewire::test(RepositoryForm::class)
            ->assertSet('claude_profile', '')
            ->assertSee('Default (~/.claude)');
    }
}
