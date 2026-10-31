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
        ];

        // GitHub returns them oldest first
        Process::fake(['*' => Process::result(json_encode(['data' => ['repository' => ['issues' => ['nodes' => [
            $issue(676, 0), $issue(677, 1), $issue(680, 0), $issue(681, 0),
        ]]]]]))]);

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
}
