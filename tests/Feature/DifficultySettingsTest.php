<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Dashboard;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use App\Livewire\Settings;
use App\Models\Repository;
use App\Models\Setting;
use App\Services\GitHub;
use App\Support\Ladder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DifficultySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ladder_and_fallback_start_at_the_adr_values(): void
    {
        Livewire::test(Settings::class)
            ->assertSet('ladder.trivial', ['model' => 'sonnet', 'effort' => 'low'])
            ->assertSet('ladder.large', ['model' => 'opus', 'effort' => 'medium'])
            ->assertSet('ladder.hard', ['model' => 'opus', 'effort' => 'high'])
            ->assertSet('fallback', ['model' => 'sonnet', 'effort' => 'high']);
    }

    public function test_a_ladder_in_order_is_saved_with_the_fallback(): void
    {
        Livewire::test(Settings::class)
            ->set('ladder.medium', ['model' => 'opus', 'effort' => 'low'])
            ->set('ladder.hard', ['model' => 'fable', 'effort' => 'max'])
            ->set('fallback', ['model' => 'opus', 'effort' => 'low'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['model' => 'opus', 'effort' => 'low'], Ladder::steps()['medium']);
        $this->assertSame(['model' => 'fable', 'effort' => 'max'], Ladder::steps()['hard']);
        $this->assertSame(['model' => 'opus', 'effort' => 'low'], Ladder::fallback());
    }

    public function test_equal_steps_are_in_order(): void
    {
        Livewire::test(Settings::class)
            ->set('ladder.small', ['model' => 'sonnet', 'effort' => 'low'])
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_step_cheaper_than_the_one_below_it_is_refused(): void
    {
        // A cheaper model beats a higher effort: the order is model first
        Livewire::test(Settings::class)
            ->set('ladder.hard', ['model' => 'sonnet', 'effort' => 'max'])
            ->call('save')
            ->assertHasErrors('ladder')
            ->assertSee('hard (sonnet max) is cheaper than large (opus medium) below it.');

        Livewire::test(Settings::class)
            ->set('ladder.small', ['model' => 'sonnet', 'effort' => 'low'])
            ->set('ladder.trivial', ['model' => 'sonnet', 'effort' => 'medium'])
            ->call('save')
            ->assertHasErrors('ladder');

        $this->assertSame(Ladder::DEFAULT, Ladder::steps());
    }

    public function test_haiku_and_unknown_efforts_are_refused_on_the_ladder_and_the_fallback(): void
    {
        Livewire::test(Settings::class)
            ->set('ladder.trivial', ['model' => 'haiku', 'effort' => 'low'])
            ->call('save')
            ->assertHasErrors('ladder.trivial.model');

        Livewire::test(Settings::class)
            ->set('fallback', ['model' => 'haiku', 'effort' => 'extreme'])
            ->call('save')
            ->assertHasErrors(['fallback.model', 'fallback.effort']);

        $this->assertSame(Ladder::DEFAULT, Ladder::steps());
        $this->assertSame(Ladder::DEFAULT_FALLBACK, Ladder::fallback());
    }

    private function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            ...$attributes,
        ]);
    }

    public function test_a_new_repository_starts_in_auto_mode_over_the_full_range(): void
    {
        Livewire::test(RepositoryForm::class)
            ->assertSet('model_mode', 'auto')
            ->assertSet('difficulty_floor', 'trivial')
            ->assertSet('difficulty_ceiling', 'hard');
    }

    public function test_the_form_saves_the_mode_and_the_range_and_keeps_the_fixed_step(): void
    {
        $repo = $this->repository(['model' => 'opus', 'effort' => 'low']);
        $this->app->instance(GitHub::class, Mockery::mock(GitHub::class)->shouldIgnoreMissing());

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSet('model_mode', 'fixed')
            ->set('model_mode', 'auto')
            ->set('difficulty_floor', 'small')
            ->set('difficulty_ceiling', 'large')
            ->call('save')
            ->assertHasNoErrors();

        $repo->refresh();
        $this->assertSame(['auto', 'small', 'large', 'opus', 'low'], [$repo->model_mode, $repo->difficulty_floor, $repo->difficulty_ceiling, $repo->model, $repo->effort]);
    }

    public function test_the_form_refuses_a_floor_above_the_ceiling_and_unknown_values(): void
    {
        $repo = $this->repository();

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->set('model_mode', 'auto')
            ->set('difficulty_floor', 'large')
            ->set('difficulty_ceiling', 'small')
            ->call('save')
            ->assertHasErrors('difficulty_ceiling')
            ->set('difficulty_floor', 'enormous')
            ->set('model_mode', 'manual')
            ->call('save')
            ->assertHasErrors(['difficulty_floor', 'model_mode']);

        $this->assertSame('fixed', $repo->fresh()->model_mode);
    }

    public function test_the_dashboard_popover_edits_the_range_in_auto_mode(): void
    {
        Process::fake();
        $repo = $this->repository(['model_mode' => 'auto', 'model' => 'sonnet']);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-first="trivial"')
            ->assertSeeHtml('data-second="hard"')
            ->assertSee('auto trivial–hard')
            ->assertSeeHtml("claudeSettings({$repo->id}, 'saveDifficultyRange')")
            ->call('saveDifficultyRange', $repo->id, 'small', 'large')
            ->assertReturned(null)
            ->call('saveDifficultyRange', $repo->id, 'large', 'small')
            ->assertReturned(fn ($error) => str_contains($error, 'ceiling'))
            ->call('saveDifficultyRange', $repo->id, 'opus', 'high')
            ->assertReturned(fn ($error) => str_contains($error, 'floor'));

        $repo->refresh();
        $this->assertSame(['small', 'large', 'sonnet', null], [$repo->difficulty_floor, $repo->difficulty_ceiling, $repo->model, $repo->effort]);
    }

    public function test_the_dashboard_and_run_page_show_the_runs_step_and_difficulty(): void
    {
        Process::fake();
        $run = $this->repository(['model_mode' => 'auto'])->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'status' => RunStatus::Failed,
            'model' => 'opus', 'effort' => 'medium', 'difficulty' => 'large', 'difficulty_source' => 'label',
        ]);

        Livewire::test(Dashboard::class)->assertSee('opus medium · large');
        Livewire::test(RunDetail::class, ['run' => $run])->assertSeeHtml('<dt>Difficulty</dt><dd>large (label)</dd>');
    }

    public function test_an_unchanged_ladder_is_not_stored_so_new_defaults_still_apply(): void
    {
        Livewire::test(Settings::class)->call('save')->assertHasNoErrors();

        $this->assertNull(Setting::get('ladder'));
        $this->assertNull(Setting::get('fallback_step'));
    }
}
