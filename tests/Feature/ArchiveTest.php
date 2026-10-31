<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Archive;
use App\Livewire\RepositoryForm;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): Repository
    {
        return Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => true,
            'model' => 'opus',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);
    }

    public function test_archiving_stops_a_repository_and_moves_it_off_the_dashboard(): void
    {
        $repo = $this->repository();
        Process::fake();

        Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
            ->assertSee('Archive')
            ->call('archive')
            ->assertRedirect(route('dashboard'));

        $repo->refresh();
        $this->assertNotNull($repo->archived_at);
        $this->assertFalse($repo->enabled);
        $this->assertSame('opus', $repo->model);   // settings are kept for later

        $this->get('/')->assertDontSee('owner/app');
        $this->get('/archive')->assertSee('owner/app');
    }

    public function test_a_repository_with_work_in_flight_cannot_be_archived(): void
    {
        foreach ([RunStatus::Working, RunStatus::WatchingPr] as $status) {
            $repo = $this->repository();
            $repo->runs()->create([
                'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet',
                'status' => $status,
            ]);

            Livewire::test(RepositoryForm::class, ['repository' => $repo->fresh()])
                ->assertSeeHtml('disabled title="Finish or cancel its runs and watched PRs first"')
                ->call('archive')
                ->assertNoRedirect();

            $this->assertNull($repo->fresh()->archived_at);
        }
    }

    public function test_restoring_brings_a_repository_back_stopped(): void
    {
        $repo = $this->repository();
        $repo->update(['archived_at' => now(), 'enabled' => false]);
        Process::fake();

        Livewire::test(Archive::class)
            ->assertSee('owner/app')
            ->call('restore', $repo->id)
            ->assertDontSee('owner/app');

        $repo->refresh();
        $this->assertNull($repo->archived_at);
        $this->assertFalse($repo->enabled);

        $this->get('/')->assertSee('owner/app');
    }

    public function test_removing_from_the_archive_deletes_the_repository(): void
    {
        $repo = $this->repository();
        $repo->update(['archived_at' => now(), 'enabled' => false]);

        Livewire::test(Archive::class)
            ->call('remove', $repo->id)
            ->assertSee('Nothing archived');

        $this->assertNull($repo->fresh());
    }

    public function test_the_masthead_links_to_the_archive_instead_of_the_lines(): void
    {
        Process::fake();

        $this->get('/')
            ->assertSee('href="'.route('archive').'"', false)
            ->assertDontSee('>Lines</a>', false);
    }
}
