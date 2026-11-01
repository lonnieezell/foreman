<?php

namespace Tests\Feature;

use App\Livewire\RepositoryForm;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class RepositoryFormTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-form-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/.git");
        File::ensureDirectoryExists("{$this->dir}/src-tauri");
        file_put_contents("{$this->dir}/package.json", json_encode(['scripts' => ['test' => 'x']]));
        file_put_contents("{$this->dir}/src-tauri/Cargo.toml", '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => $this->dir, 'github_repo' => 'owner/app', 'enabled' => true,
            'allowed_tools' => 'Read', 'setup_script' => 'true', 'base_branch' => 'main',
            'ready_label' => 'ready-for-agent', 'working_label' => 'agent-working', 'human_label' => 'needs-human',
            'cleanup_worktrees' => true, 'watch_prs' => true,
            'model' => 'sonnet', 'concurrency' => 1, 'poll_interval_minutes' => 10, 'max_turns' => 40, 'max_minutes' => 45,
            ...$attributes,
        ]);
    }

    private function defaultPrompt(): string
    {
        return file_get_contents(resource_path('prompts/default.md'));
    }

    public function test_remove_them_swaps_the_old_git_entry_for_the_narrow_ones_and_drops_the_rest(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => 'Read,Bash(git:*),Bash(npm:*),Bash(gh issue:*),Bash(gh pr create:*)'])])
            ->assertSee('Remove them')
            ->call('removeLegacyAllowedTools')
            ->assertSet('allowed_tools', 'Read,Bash(npm:*),Bash(git status:*),Bash(git diff:*),Bash(git log:*),Bash(git show:*),Bash(git add:*),Bash(git commit:*)')
            ->assertDontSee('Remove them');
    }

    public function test_remove_them_adds_only_the_git_entries_that_are_missing(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => 'Read,Bash(git:*),Bash(git add:*),Bash(git commit:*)'])])
            ->call('removeLegacyAllowedTools')
            ->assertSet('allowed_tools', 'Read,Bash(git add:*),Bash(git commit:*),Bash(git status:*),Bash(git diff:*),Bash(git log:*),Bash(git show:*)');
    }

    public function test_remove_them_adds_no_git_entries_when_only_gh_entries_were_old(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => 'Read,Bash(gh issue:*)'])])
            ->call('removeLegacyAllowedTools')
            ->assertSet('allowed_tools', 'Read');
    }

    public function test_a_list_that_cannot_commit_gets_a_warning(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => 'Read,Bash(npm:*)'])])
            ->assertSee("can't run git add and git commit", escape: false);

        foreach ([Repository::DEFAULT_ALLOWED_TOOLS, 'Read,Bash(git:*)', 'Read,Bash'] as $tools) {
            Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => $tools])])
                ->assertDontSee("can't run git add and git commit", escape: false);
        }
    }

    public function test_the_old_entries_notice_says_only_git_widens_anything(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['allowed_tools' => 'Read,Bash(git:*)'])])
            ->assertSee('lets the agent run any git command')
            ->assertDontSee('still lets the agent push or use');
    }

    public function test_it_shows_the_stacks_found_in_the_checkout(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->assertSeeInOrder(['Detected stacks', 'TypeScript', 'Rust']);
    }

    public function test_detect_refreshes_the_stacks(): void
    {
        $github = $this->mock(GitHub::class);
        $github->shouldReceive('detectRepo')->andReturn('owner/app');

        Livewire::test(RepositoryForm::class)
            ->assertDontSee('Detected stacks')
            ->set('local_path', $this->dir)
            ->call('detect')
            ->assertSeeInOrder(['Detected stacks', 'TypeScript', 'Rust']);
    }

    public function test_use_detected_checks_puts_back_the_default_verify_script(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['base_branch' => 'develop'])])
            ->set('verify_script', 'make check')
            ->call('useDetectedChecks')
            ->assertSet('verify_script', Repository::defaultVerifyScript($this->dir, 'origin/develop'));
    }

    public function test_the_instructions_are_flagged_when_edited(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->assertDontSee('Edited from the default')
            ->set('prompt_template', "Fix it.\n".$this->defaultPrompt())
            ->assertSee('Edited from the default');
    }

    public function test_it_lists_the_placeholders_the_prompt_uses(): void
    {
        $form = Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->set('prompt_template', "Fix {{issue_text}} on {{branch}}.\nThen {{branch}} again and {{unknown}}.");

        $this->assertSame(['issue_text', 'branch'], $form->instance()->promptPlaceholders);
        $this->assertSame(2, $form->instance()->promptLineCount);
    }

    public function test_the_full_editor_hint_lists_issue_text(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->assertSeeHtml('<code>{{issue_text}}</code>');
    }

    public function test_compare_with_default_marks_the_changed_lines(): void
    {
        $lines = explode("\n", rtrim($this->defaultPrompt(), "\n"));
        $first = $lines[0];
        $lines[0] = 'A changed first line';

        $diff = Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->set('prompt_template', implode("\n", $lines))
            ->instance()->promptDiff;

        $this->assertContains(['type' => 'removed', 'text' => $first], $diff);
        $this->assertContains(['type' => 'added', 'text' => 'A changed first line'], $diff);
        $this->assertCount(count($lines) - 1, array_filter($diff, fn ($line) => $line['type'] === 'same'));
    }

    public function test_discard_puts_back_the_saved_values(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->set('name', 'renamed')
            ->set('locked_paths', 'config/**')
            ->call('discard')
            ->assertSet('name', 'app')
            ->assertSet('locked_paths', '');
    }

    public function test_the_header_sums_up_the_repository(): void
    {
        Livewire::test(RepositoryForm::class, ['repository' => $this->repository(['model' => 'opus'])])
            ->assertSeeInOrder(['app', 'Active', 'owner/app → main', 'Opus · Default effort', '1 issue at a time · every 10 min']);
    }

    public function test_the_pr_watch_switch_shows_the_global_rounds(): void
    {
        config(['foreman.pr_watch.max_rounds' => 3]);

        Livewire::test(RepositoryForm::class, ['repository' => $this->repository()])
            ->assertSee('Up to 3 rounds')
            ->assertSeeHtml('href="'.route('settings').'"');
    }
}
