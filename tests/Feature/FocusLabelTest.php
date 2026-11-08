<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class FocusLabelTest extends TestCase
{
    use RefreshDatabase;

    private function repository(?string $focus = null): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'focus_label' => $focus,
        ]);
    }

    public function test_the_dashboard_pill_shows_no_focus_or_the_focus_label(): void
    {
        Process::fake();
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('class="line-focus"')
            ->assertSee('no focus')
            ->assertDontSee('focus: ');

        $repo->update(['focus_label' => 'garret-sync']);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('class="line-focus is-set"')
            ->assertSeeHtml('title="Only taking ready issues that also have this label."')
            ->assertSee('focus: garret-sync');
    }

    public function test_saving_trims_the_label_and_saves_empty_as_no_focus(): void
    {
        Process::fake();
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->call('saveFocusLabel', $repo->id, '  garret-sync ')
            ->assertReturned(null);

        $this->assertSame('garret-sync', $repo->fresh()->focus_label);

        Livewire::test(Dashboard::class)->call('saveFocusLabel', $repo->id, ' ');

        $this->assertNull($repo->fresh()->focus_label);
    }

    public function test_a_workflow_label_cannot_be_the_focus(): void
    {
        Process::fake();
        $repo = $this->repository('garret-sync');

        foreach (['ready-for-agent', 'agent-working', 'needs-human'] as $label) {
            Livewire::test(Dashboard::class)
                ->call('saveFocusLabel', $repo->id, $label)
                ->assertReturned(fn ($error) => is_string($error) && str_contains($error, 'workflow label'));
        }

        $this->assertSame('garret-sync', $repo->fresh()->focus_label);
    }

    private function fakeLabels(array $names): void
    {
        Process::fake([
            '*label* *list*' => Process::result(json_encode(array_map(fn (string $name) => ['name' => $name], $names))),
            '*' => Process::result(),
        ]);
    }

    public function test_the_options_are_no_focus_then_the_repos_labels_a_to_z_minus_the_workflow_labels(): void
    {
        $this->fakeLabels(['needs-human', 'garret-sync', 'ready-for-agent', 'bug', 'foreman:trivial', 'agent-working']);
        $repo = $this->repository();

        Livewire::test(Dashboard::class)
            ->call('focusOptions', $repo->id)
            ->assertReturned([
                'options' => [
                    ['value' => '', 'text' => 'No focus'],
                    ['value' => 'bug', 'text' => 'bug'],
                    ['value' => 'foreman:trivial', 'text' => 'foreman:trivial'],
                    ['value' => 'garret-sync', 'text' => 'garret-sync'],
                ],
                'error' => null,
            ]);

        Process::assertRan(fn ($p) => $p->command === ['gh', 'label', 'list', '--json', 'name', '--limit', '200', '--repo', 'owner/app']);
    }

    public function test_a_saved_focus_missing_on_github_stays_in_the_list_marked_not_found(): void
    {
        $this->fakeLabels(['bug']);
        $repo = $this->repository('old-sprint');

        Livewire::test(Dashboard::class)
            ->call('focusOptions', $repo->id)
            ->assertReturned([
                'options' => [
                    ['value' => '', 'text' => 'No focus'],
                    ['value' => 'bug', 'text' => 'bug'],
                    ['value' => 'old-sprint', 'text' => 'old-sprint (not found)'],
                ],
                'error' => null,
            ]);
    }

    public function test_a_failed_fetch_shows_an_error_and_still_offers_no_focus_and_the_current_focus(): void
    {
        Process::fake([
            '*label* *list*' => Process::result('', 'HTTP 502: Bad Gateway', 1),
            '*' => Process::result(),
        ]);
        $repo = $this->repository('garret-sync');

        Livewire::test(Dashboard::class)
            ->call('focusOptions', $repo->id)
            ->assertReturned(fn (array $result) => $result['options'] === [
                ['value' => '', 'text' => 'No focus'],
                ['value' => 'garret-sync', 'text' => 'garret-sync'],
            ] && str_contains($result['error'], 'Couldn’t load labels'));

        $this->assertSame('garret-sync', $repo->fresh()->focus_label);
    }
}
