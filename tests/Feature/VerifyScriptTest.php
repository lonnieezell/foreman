<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
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

class VerifyScriptTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-verify-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/frontend");
        file_put_contents("{$this->dir}/composer.json", json_encode(['require-dev' => ['laravel/pint' => '*', 'phpstan/phpstan' => '*', 'pestphp/pest' => '*']]));
        file_put_contents("{$this->dir}/frontend/package.json", json_encode(['scripts' => ['lint' => 'x', 'test' => 'x']]));
        file_put_contents("{$this->dir}/frontend/pnpm-lock.yaml", '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => $this->dir, 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => 'Read', 'setup_script' => 'true', 'base_branch' => 'main',
            'ready_label' => 'ready-for-agent', 'working_label' => 'agent-working', 'human_label' => 'needs-human',
            'cleanup_worktrees' => true, 'watch_prs' => true,
            'model' => 'sonnet', 'concurrency' => 1, 'poll_interval_minutes' => 10, 'max_turns' => 40, 'max_minutes' => 45,
            ...$attributes,
        ]);
    }

    private const DEFAULT = "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/pint --test --)\nvendor/bin/phpstan analyse\nvendor/bin/pest\n(cd 'frontend' && pnpm run lint)\n(cd 'frontend' && pnpm run test)";

    public function test_the_default_is_built_from_the_detected_stacks(): void
    {
        $this->assertSame(self::DEFAULT, Repository::defaultVerifyScript($this->dir, 'origin/main'));
        $this->assertStringContainsString("--merge-base 'origin/develop'", Repository::defaultVerifyScript($this->dir, 'origin/develop'));
        $this->assertSame('', Repository::defaultVerifyScript("{$this->dir}/missing", 'origin/main'));
    }

    public function test_the_form_starts_with_the_default_for_a_repository_without_one(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->assertSet('verify_script', self::DEFAULT)
            ->assertSeeHtml('id="verify_script"');
    }

    public function test_the_forms_default_follows_the_repositorys_base_branch(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['base_branch' => 'develop'])])
            ->assertSet('verify_script', str_replace('origin/main', 'origin/develop', self::DEFAULT));
    }

    public function test_detecting_the_checkout_fills_in_the_default(): void
    {
        mkdir("{$this->dir}/.git");
        $github = Mockery::mock(GitHub::class);
        $github->allows('detectRepo')->andReturn('owner/app');

        Livewire::test(RepositoryForm::class)
            ->set('local_path', $this->dir)
            ->call('detect', $github)
            ->assertSet('verify_script', self::DEFAULT);
    }

    public function test_the_script_can_be_saved_and_cleared(): void
    {
        mkdir("{$this->dir}/.git");
        $repo = $this->repository();
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('verify_script', 'make check')
            ->call('save', $github)
            ->assertHasNoErrors();
        $this->assertSame('make check', $repo->fresh()->verify_script);

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->set('verify_script', '  ')
            ->call('save', $github)
            ->assertHasNoErrors();
        $this->assertNull($repo->fresh()->verify_script);
    }

    public function test_saving_the_unchanged_default_stores_nothing_so_it_follows_the_base_branch(): void
    {
        mkdir("{$this->dir}/.git");
        $repo = $this->repository(['verify_script' => 'make check']);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();

        Livewire::test(RepositoryForm::class, ['repository' => $repo])
            ->set('base_branch', 'develop')
            ->set('verify_script', str_replace('origin/main', 'origin/develop', self::DEFAULT))
            ->call('save', $github)
            ->assertHasNoErrors();

        $this->assertNull($repo->fresh()->verify_script);
    }

    public function test_the_form_offers_to_copy_the_verify_script_prompt(): void
    {
        Livewire::test(RepositoryForm::class)
            ->assertSeeHtml("copyFrom('verifyScriptPrompt')");
    }

    public function test_the_prompt_uses_the_unsaved_form_values(): void
    {
        $prompt = Livewire::test(RepositoryForm::class)
            ->set('base_branch', 'develop')
            ->set('verify_script', "make lint\nmake test")
            ->instance()
            ->verifyScriptPrompt();

        $this->assertStringContainsString('origin/develop', $prompt);
        $this->assertStringContainsString("make lint\nmake test", $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_every_run_gets_a_stop_hook_with_the_verify_script_and_a_fresh_counter(): void
    {
        $repo = $this->repository(['verify_script' => 'make check', 'base_branch' => 'develop']);
        $run = $repo->runs()->create([
            'issue_number' => 9, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/9',
            'branch' => 'agent/issue-9', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            'worktree_path' => $this->dir,
        ]);
        $stops = storage_path("foreman/hooks/run-{$run->id}-stops");
        File::ensureDirectoryExists(dirname($stops));
        file_put_contents($stops, '2');   // left by an earlier start of the same run

        Process::fake(['*' => Process::result(json_encode(['type' => 'result', 'num_turns' => 1]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);

        $command = null;
        Process::assertRan(function (PendingProcess $p) use (&$command) {
            return $p->command[0] === 'claude' && ($command = $p->command);
        });
        $settings = $command[array_search('--settings', $command) + 1];
        $config = json_decode(file_get_contents($settings), true);
        $rulesFile = str_replace('-settings.json', '-rules.json', $settings);
        $rules = json_decode(file_get_contents($rulesFile), true);

        $this->assertStringContainsString('foreman:stop', $config['hooks']['Stop'][0]['hooks'][0]['command']);
        $this->assertStringContainsString($rulesFile, $config['hooks']['Stop'][0]['hooks'][0]['command']);
        $this->assertSame('make check', $rules['verify_script']);
        $this->assertSame('origin/develop', $rules['base']);
        $this->assertSame($stops, $rules['stop_counter']);
        $this->assertFileDoesNotExist($stops);

        File::delete([$settings, $rulesFile]);
    }
}
