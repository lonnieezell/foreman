<?php

namespace Tests\Support;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** A repository, a run and a worktree for testing the real `claude` adapter, with every process it starts faked and remembered. */
trait ClaudeCliHarness
{
    public const RESULT = '{"type":"result","num_turns":2,"total_cost_usd":0.5,"session_id":"sess-9","usage":{"input_tokens":10,"output_tokens":20,"cache_read_input_tokens":30,"cache_creation_input_tokens":40}}';

    /** @var list<PendingProcess> */
    protected array $started = [];

    protected string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        FakeWorktree::install($this->app);

        $this->worktree = sys_get_temp_dir().'/foreman-cli-'.uniqid();
        File::ensureDirectoryExists($this->worktree);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        Run::all()->each(fn (Run $run) => File::delete([$run->logPath(), $run->rawLogPath()]));

        parent::tearDown();
    }

    protected function repository(array $attributes = []): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => 'Read,Edit', 'setup_script' => 'true', 'max_turns' => 40,
            ...$attributes,
        ]);
    }

    protected function makeRun(array $repository = [], array $run = []): Run
    {
        return $this->repository($repository)->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Fix', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'status' => RunStatus::Queued, 'model' => 'sonnet',
            ...$run,
        ]);
    }

    /** Fakes every process as printing `$output`, remembering what started. */
    protected function fakeClaude(string $output = self::RESULT, int $exit = 0): void
    {
        Process::fake(function (PendingProcess $process) use ($output, $exit) {
            $this->started[] = $process;

            return Process::result($output, '', $exit);
        });
    }

    protected function after(array $command, string $flag): ?string
    {
        $at = array_search($flag, $command, true);

        return $at === false ? null : $command[$at + 1];
    }

    /** @return list<array> The stream-json messages Foreman wrote to the agent's stdin, in order. */
    protected function sentMessages(int $process = 0): array
    {
        return ClaudeStdin::messages($this->started[$process]);
    }
}
