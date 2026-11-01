<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Repository;
use App\Models\Setting;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class IntakeGateTest extends TestCase
{
    use RefreshDatabase;

    private function issue(int $number, string $actor = 'alice', string $actorType = 'User', string $labeledAt = '2026-11-01T10:00:00Z', ?string $editedAt = null): array
    {
        return [
            'number' => $number, 'title' => "Issue {$number}", 'url' => "https://example.test/{$number}",
            'issueDependenciesSummary' => ['blockedBy' => 0],
            'lastEditedAt' => $editedAt,
            'timelineItems' => ['nodes' => [
                ['createdAt' => '2026-10-01T10:00:00Z', 'label' => ['name' => 'ready-for-agent'], 'actor' => ['__typename' => 'User', 'login' => 'old-actor']],
                ['createdAt' => $labeledAt, 'label' => ['name' => 'ready-for-agent'], 'actor' => ['__typename' => $actorType, 'login' => $actor]],
                ['createdAt' => '2026-11-01T11:00:00Z', 'label' => ['name' => 'other'], 'actor' => ['__typename' => 'User', 'login' => 'someone-else']],
            ]],
        ];
    }

    private function fakeGitHub(array $issues, array $permissions = []): void
    {
        Process::fake([
            '*graphql*' => Process::result(json_encode(['data' => ['repository' => ['issues' => ['nodes' => $issues]]]])),
            '*collaborators*' => function (PendingProcess $p) use ($permissions) {
                $login = explode('/', implode(' ', $p->command))[4] ?? '';
                $permission = $permissions[$login] ?? null;

                return $permission
                    ? Process::result(json_encode(['permission' => $permission]))
                    : Process::result('', 'Not Found', 1);
            },
        ]);
    }

    private function repo(): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ])->fresh();
    }

    public function test_issues_labelled_by_someone_with_write_access_are_picked_up(): void
    {
        $this->fakeGitHub([$this->issue(1, 'alice'), $this->issue(2, 'maya')], ['alice' => 'write', 'maya' => 'admin']);
        $repo = $this->repo();

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([1, 2], array_column($issues, 'number'));
        $this->assertNull($repo->fresh()->intake_skips);
    }

    public function test_an_issue_labelled_by_a_bot_is_skipped(): void
    {
        $this->fakeGitHub([$this->issue(1, 'some-bot', 'Bot'), $this->issue(2, 'alice')], ['some-bot' => 'write', 'alice' => 'write']);
        $repo = $this->repo();

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([2], array_column($issues, 'number'));
        $this->assertSame([['number' => 1, 'reason' => 'the ready label was added by a bot (some-bot)']], $repo->fresh()->intake_skips);
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), 'collaborators/some-bot'));
    }

    public function test_an_issue_labelled_by_a_bot_is_picked_up_when_bot_labels_are_trusted(): void
    {
        Setting::put('trust_bot_labels', 1);
        $this->fakeGitHub([$this->issue(1, 'some-bot', 'Bot'), $this->issue(2, 'other-bot', 'Bot', editedAt: '2026-11-01T10:05:00Z')]);
        $repo = $this->repo();

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([1], array_column($issues, 'number'));
        $this->assertSame([['number' => 2, 'reason' => 'the issue was edited after the ready label was added']], $repo->fresh()->intake_skips);
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), 'collaborators/'));
    }

    public function test_an_issue_labelled_by_someone_without_write_access_is_skipped(): void
    {
        $this->fakeGitHub([$this->issue(1, 'stranger'), $this->issue(2, 'reader'), $this->issue(3, 'alice')],
            ['reader' => 'read', 'alice' => 'maintain']);
        $repo = $this->repo();

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([3], array_column($issues, 'number'));
        $this->assertSame([
            ['number' => 1, 'reason' => 'the ready label was added by stranger, who doesn’t have write access'],
            ['number' => 2, 'reason' => 'the ready label was added by reader, who doesn’t have write access'],
        ], $repo->fresh()->intake_skips);
    }

    public function test_an_issue_edited_after_the_label_was_added_is_skipped(): void
    {
        $this->fakeGitHub([
            $this->issue(1, editedAt: '2026-11-01T10:05:00Z'),
            $this->issue(2, editedAt: '2026-11-01T09:00:00Z'),
        ], ['alice' => 'write']);
        $repo = $this->repo();

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([2], array_column($issues, 'number'));
        $this->assertSame([['number' => 1, 'reason' => 'the issue was edited after the ready label was added']], $repo->fresh()->intake_skips);
    }

    public function test_write_access_is_checked_once_per_labeller_per_poll(): void
    {
        $this->fakeGitHub([$this->issue(1), $this->issue(2), $this->issue(3)], ['alice' => 'write']);

        app(GitHub::class)->readyIssues($this->repo(), 5);

        Process::assertRanTimes(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), 'collaborators/alice/permission'), 1);
    }

    public function test_skips_clear_on_the_next_poll_and_show_on_the_repository_row(): void
    {
        $this->fakeGitHub([$this->issue(7, 'stranger')], []);
        $repo = $this->repo();
        $repo->update(['enabled' => true]);

        app(GitHub::class)->readyIssues($repo, 5);

        Livewire::test(Dashboard::class)
            ->assertSee('Skipped by the intake gate')
            ->assertSee('#7')
            ->assertSee('who doesn’t have write access');

        $this->fakeGitHub([], []);
        app(GitHub::class)->readyIssues($repo->fresh(), 5);

        $this->assertNull($repo->fresh()->intake_skips);
    }
}
