<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class TokenUsageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): Run
    {
        $repo = Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);

        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            'resume_session_id' => 'abc', 'worktree_path' => sys_get_temp_dir(),
        ]);
    }

    public function test_token_usage_is_saved_from_the_result_event(): void
    {
        $run = $this->makeRun();

        Process::fake(['*' => Process::result(json_encode([
            'type' => 'result',
            'num_turns' => 3,
            'total_cost_usd' => 0.42,
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 3400,
                'cache_read_input_tokens' => 250000,
                'cache_creation_input_tokens' => 18000,
            ],
        ]))]);
        $github = Mockery::mock(GitHub::class)->shouldIgnoreMissing();
        $github->allows('labels')->andReturn([]);
        $github->allows('pullRequestFor')->andReturn('https://example.test/pr/1');

        (new ProcessRun($run))->handle($github);
        File::delete([$run->logPath(), $run->rawLogPath()]);

        $run->refresh();
        $this->assertSame(120, $run->input_tokens);
        $this->assertSame(3400, $run->output_tokens);
        $this->assertSame(250000, $run->cache_read_tokens);
        $this->assertSame(18000, $run->cache_write_tokens);
        $this->assertSame('default', $run->claude_profile);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSee('Tokens in')
            ->assertSee('268.1K')   // new + cache read + cache write
            ->assertSee('3.4K');
    }

    public function test_runs_without_token_counts_hide_them(): void
    {
        Livewire::test(RunDetail::class, ['run' => $this->makeRun()])
            ->assertDontSee('Tokens in');
    }

    public function test_dashboard_tallies_todays_tokens_per_profile(): void
    {
        $usage = fn (string $profile, int $in, int $out) => [
            'claude_profile' => $profile, 'input_tokens' => $in, 'output_tokens' => $out,
            'cache_read_tokens' => 1000, 'cache_write_tokens' => 0,
        ];

        $this->makeRun()->update($usage('work', 500, 2000));
        $this->makeRun()->update($usage('work', 500, 2000));
        $this->makeRun()->update($usage('personal', 0, 700));
        $this->makeRun()->update([...$usage('personal', 0, 99999), 'created_at' => now()->subDay()]);
        $this->makeRun()->update(['claude_profile' => 'work']);   // no token counts yet

        $this->assertSame([
            'personal' => ['in' => 1000, 'out' => 700],
            'work' => ['in' => 3000, 'out' => 4000],
        ], Run::tokensTodayByProfile());

        $this->get('/')
            ->assertSee('3K in / 4K out today')
            ->assertSee('1K in / 700 out today');   // personal: no repository uses it, but it spent tokens today
    }
}
