<?php

namespace Tests\Feature;

use App\Livewire\RepositoryForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class FolderPickerTest extends TestCase
{
    use RefreshDatabase;

    /** Every OS's picker, so the test passes wherever it runs. Stray processes fail, so no real dialog opens. */
    private function fakePicker($result): void
    {
        Process::preventStrayProcesses();
        Process::fake([
            "'osascript'*" => $result,
            "'powershell'*" => $result,
            "'zenity'*" => $result,
            "'gh' 'repo' 'view'*" => Process::result('owner/app'),
        ]);
    }

    public function test_browsing_fills_the_path_and_detects_the_repository(): void
    {
        $this->fakePicker(Process::result(base_path()."/\n"));

        Livewire::test(RepositoryForm::class)
            ->call('browse')
            ->assertHasNoErrors()
            ->assertSet('local_path', base_path())
            ->assertSet('github_repo', 'owner/app')
            ->assertSet('name', 'app');
    }

    public function test_cancelling_the_picker_changes_nothing(): void
    {
        $this->fakePicker(Process::result('', exitCode: 1));

        Livewire::test(RepositoryForm::class)
            ->set('local_path', '~/code/typed')
            ->call('browse')
            ->assertHasNoErrors()
            ->assertSet('local_path', '~/code/typed')
            ->assertSet('github_repo', '');
    }
}
