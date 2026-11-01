<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class RunEndingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unexpected_ending_keeps_the_agents_last_message(): void
    {
        $run = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ])->runs()->create([
            'issue_number' => 907, 'branch' => 'agent/issue-907', 'model' => 'sonnet', 'status' => RunStatus::Queued,
            'resume_session_id' => 'abc', 'worktree_path' => sys_get_temp_dir(),
        ]);

        Process::fake(['*' => Process::result(json_encode([
            'type' => 'result', 'num_turns' => 8,
            'result' => "The fix is already on `main`.\n\nSee `Finding::has_title` in findings.rs:114.",
        ]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->expects('flagForHuman')->withArgs(fn ($repo, $issue, $comment) => $issue === 907
            && str_contains($comment, "> The fix is already on `main`.\n>\n> See `Finding::has_title` in findings.rs:114."));

        (new ProcessRun($run))->handle($github);
        $run->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('The agent finished without a readable result file', $run->error);
        $this->assertStringContainsString('See `Finding::has_title` in findings.rs:114.', $run->error);

        File::delete([$run->logPath(), $run->rawLogPath()]);
    }
}
