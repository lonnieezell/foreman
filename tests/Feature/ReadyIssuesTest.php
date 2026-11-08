<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReadyIssuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_issues_are_skipped_and_the_oldest_come_first(): void
    {
        $issue = fn (int $number, int $blockedBy) => [
            'number' => $number, 'title' => "Issue {$number}", 'url' => "https://example.test/{$number}",
            'issueDependenciesSummary' => ['blockedBy' => $blockedBy],
            'lastEditedAt' => null,
            'timelineItems' => ['nodes' => [[
                'createdAt' => '2026-11-01T10:00:00Z', 'label' => ['name' => 'ready-for-agent'], 'actor' => ['__typename' => 'User', 'login' => 'alice'],
            ]]],
        ];

        // GitHub returns them oldest first
        Process::fake([
            '*collaborators*' => Process::result(json_encode(['permission' => 'write'])),
            '*' => Process::result(json_encode(['data' => ['repository' => ['issues' => ['nodes' => [
                $issue(676, 0), $issue(677, 1), $issue(680, 0), $issue(681, 0),
            ]]]]])),
        ]);

        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ]);

        $issues = app(GitHub::class)->readyIssues($repo->fresh(), 2);

        $this->assertSame([676, 680], array_column($issues, 'number'));
        $this->assertSame(['number' => 676, 'title' => 'Issue 676', 'url' => 'https://example.test/676'], $issues[0]);
        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'api', 'graphql']
            && in_array('label=ready-for-agent', $p->command, true));
    }

    /** An issue labelled ready by alice, who has write access, plus the given labels. */
    private function labelledIssue(int $number, array $labels, string $actor = 'alice'): array
    {
        return [
            'number' => $number, 'title' => "Issue {$number}", 'url' => "https://example.test/{$number}",
            'issueDependenciesSummary' => ['blockedBy' => 0],
            'lastEditedAt' => null,
            'labels' => ['nodes' => array_map(fn (string $name) => ['name' => $name], ['ready-for-agent', ...$labels])],
            'timelineItems' => ['nodes' => [[
                'createdAt' => '2026-11-01T10:00:00Z', 'label' => ['name' => 'ready-for-agent'], 'actor' => ['__typename' => 'User', 'login' => $actor],
            ]]],
        ];
    }

    private function focusedRepo(?string $focus): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
            'focus_label' => $focus,
        ])->fresh();
    }

    private function fakeIssues(array $issues): void
    {
        Process::fake([
            '*collaborators/alice*' => Process::result(json_encode(['permission' => 'write'])),
            '*collaborators*' => Process::result('', 'Not Found', 1),
            '*' => Process::result(json_encode(['data' => ['repository' => ['issues' => ['nodes' => $issues]]]])),
        ]);
    }

    public function test_a_focus_label_takes_only_ready_issues_that_also_have_it(): void
    {
        $this->fakeIssues([
            $this->labelledIssue(1, ['bug']),
            $this->labelledIssue(2, ['garret-sync']),
            $this->labelledIssue(3, ['bug', 'garret-sync']),
        ]);

        $issues = app(GitHub::class)->readyIssues($this->focusedRepo('garret-sync'), 5);

        $this->assertSame([2, 3], array_column($issues, 'number'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), 'labels(first: 100) { nodes { name } }'));
    }

    public function test_out_of_focus_issues_skip_the_intake_gate_silently_and_in_focus_ones_still_face_it(): void
    {
        $this->fakeIssues([
            $this->labelledIssue(1, [], actor: 'mallory'),
            $this->labelledIssue(2, ['garret-sync'], actor: 'mallory'),
            $this->labelledIssue(3, ['garret-sync']),
        ]);
        $repo = $this->focusedRepo('garret-sync');

        $issues = app(GitHub::class)->readyIssues($repo, 5);

        $this->assertSame([3], array_column($issues, 'number'));
        $this->assertSame([2], array_column($repo->fresh()->intake_skips, 'number'));
    }
}
