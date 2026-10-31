<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Dashboard;
use App\Livewire\Settings;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DesktopNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function repository(string $name = 'app', bool $archived = false): Repository
    {
        return Repository::create([
            'name' => $name,
            'local_path' => base_path(),
            'github_repo' => "owner/{$name}",
            'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
            'archived_at' => $archived ? now() : null,
        ]);
    }

    private function makeRun(Repository $repo, int $issue, RunStatus $status, array $extra = []): Run
    {
        return $repo->runs()->create([
            'issue_number' => $issue, 'issue_title' => "Title {$issue}", 'issue_url' => "https://example.test/issues/{$issue}",
            'branch' => "agent/issue-{$issue}", 'model' => 'sonnet', 'status' => $status, ...$extra,
        ]);
    }

    private function notificationsOn(): void
    {
        Setting::put('desktop_notifications', 1);
    }

    public function test_the_setting_is_off_by_default_and_persists_when_turned_on(): void
    {
        $this->assertFalse(Setting::desktopNotifications());

        Livewire::test(Settings::class)
            ->assertSet('desktop_notifications', false)
            ->set('desktop_notifications', true);

        $this->assertTrue(Setting::desktopNotifications());

        Livewire::test(Settings::class)
            ->assertSet('desktop_notifications', true)
            ->set('desktop_notifications', false);

        $this->assertFalse(Setting::desktopNotifications());
    }

    public function test_an_issue_entering_each_attention_section_notifies_exactly_once(): void
    {
        Process::fake();
        $this->notificationsOn();
        $repo = $this->repository();
        $needs = $this->makeRun($repo, 1, RunStatus::Working);
        $failed = $this->makeRun($repo, 2, RunStatus::Working);
        $ready = $this->makeRun($repo, 3, RunStatus::Working);

        $component = Livewire::test(Dashboard::class)->assertNotDispatched('foreman-notify');

        $needs->moveTo(RunStatus::NeedsHuman, ['error' => 'Which database?']);
        $failed->moveTo(RunStatus::Failed, ['error' => 'tests timed out']);
        $ready->moveTo(RunStatus::PrReady);

        $component->call('$refresh')->assertDispatched('foreman-notify', function (string $event, array $params) use ($needs, $failed, $ready) {
            $titles = collect($params['notifications'])->pluck('title', 'url');

            return count($params['notifications']) === 3
                && $titles[route('runs.show', $needs)] === '#1 app needs your reply'
                && $titles[route('runs.show', $failed)] === '#2 app failed'
                && $titles[route('runs.show', $ready)] === '#3 app is ready for review';
        });

        $component->call('$refresh')->assertNotDispatched('foreman-notify');
    }

    public function test_the_body_carries_the_reason(): void
    {
        Process::fake();
        $this->notificationsOn();
        $repo = $this->repository();
        $run = $this->makeRun($repo, 7, RunStatus::Working);

        $component = Livewire::test(Dashboard::class);
        $run->moveTo(RunStatus::Failed, ['error' => 'tests timed out']);

        $component->call('$refresh')->assertDispatched('foreman-notify', fn (string $event, array $params) => $params['notifications'][0]['body'] === 'tests timed out');
    }

    public function test_issues_already_waiting_when_the_page_loads_do_not_notify(): void
    {
        Process::fake();
        $this->notificationsOn();
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::NeedsHuman);
        $this->makeRun($repo, 2, RunStatus::Failed);
        $this->makeRun($repo, 3, RunStatus::PrOpened);

        Livewire::test(Dashboard::class)
            ->assertNotDispatched('foreman-notify')
            ->call('$refresh')
            ->assertNotDispatched('foreman-notify');
    }

    public function test_a_retry_that_fails_again_notifies_again(): void
    {
        Process::fake();
        $this->notificationsOn();
        $repo = $this->repository();
        $this->makeRun($repo, 1, RunStatus::Failed);

        $component = Livewire::test(Dashboard::class);

        $retry = $this->makeRun($repo, 1, RunStatus::Queued);
        $component->call('$refresh')->assertNotDispatched('foreman-notify');

        $retry->moveTo(RunStatus::Failed, ['error' => 'still broken']);
        $component->call('$refresh')->assertDispatched('foreman-notify');
    }

    public function test_dismissed_issues_and_archived_repositories_never_notify(): void
    {
        Process::fake();
        $this->notificationsOn();
        $repo = $this->repository();
        $archived = $this->repository('old', archived: true);
        $dismissed = $this->makeRun($repo, 1, RunStatus::Working);
        $inArchived = $this->makeRun($archived, 2, RunStatus::Working);

        $component = Livewire::test(Dashboard::class);

        $dismissed->moveTo(RunStatus::Failed, ['handled_at' => now()]);
        $inArchived->moveTo(RunStatus::Failed);

        $component->call('$refresh')->assertNotDispatched('foreman-notify');
    }

    public function test_nothing_is_sent_when_the_setting_is_off_and_turning_it_on_does_not_replay(): void
    {
        Process::fake();
        $repo = $this->repository();
        $run = $this->makeRun($repo, 1, RunStatus::Working);

        $component = Livewire::test(Dashboard::class);
        $run->moveTo(RunStatus::Failed);

        $component->call('$refresh')->assertNotDispatched('foreman-notify');

        $this->notificationsOn();
        $component->call('$refresh')->assertNotDispatched('foreman-notify');
    }
}
