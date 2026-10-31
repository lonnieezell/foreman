<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunLogTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Run ids restart in the test database, so keep log files away from the real ones
        $this->storage = sys_get_temp_dir().'/foreman-tests-'.getmypid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

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
            'issue_number' => 7, 'issue_title' => 'Fix', 'branch' => 'agent/issue-7', 'model' => 'sonnet',
            'status' => RunStatus::Working, 'worktree_path' => '/tmp/wt/issue-7',
        ]);
    }

    public function test_log_lines_are_grouped_into_entries_by_kind(): void
    {
        $run = $this->makeRun();
        File::ensureDirectoryExists(dirname($run->logPath()));
        File::put($run->logPath(), implode("\n", [
            "  s=open(p).read()",                      // cut off mid-entry by the tail
            "[00:47:08] $ cat > a.ts <<'EOF'",
            'import { x } from "y";',
            '',
            'export const a = 1...',
            "[00:47:14] That's my own edit again.",
            '[00:47:15] Read /tmp/wt/issue-7/src/pdf.ts',
            '[00:47:16] Grep',
            '[00:47:17] Agent flagged the issue for a human.',
            '[00:47:18] Failed: boom',
            '[00:47:19] PR opened: https://github.com/owner/app/pull/1',
        ])."\n");

        $entries = $run->logEntries();

        $this->assertSame([
            ['time' => '', 'kind' => 'message', 'text' => '  s=open(p).read()', 'more' => ''],
            ['time' => '00:47:08', 'kind' => 'command', 'text' => "cat > a.ts <<'EOF'", 'more' => "import { x } from \"y\";\n\nexport const a = 1..."],
            ['time' => '00:47:14', 'kind' => 'message', 'text' => "That's my own edit again.", 'more' => ''],
            ['time' => '00:47:15', 'kind' => 'tool', 'text' => 'Read src/pdf.ts', 'more' => ''],
            ['time' => '00:47:16', 'kind' => 'tool', 'text' => 'Grep', 'more' => ''],
            ['time' => '00:47:17', 'kind' => 'message', 'text' => 'Agent flagged the issue for a human.', 'more' => ''],
            ['time' => '00:47:18', 'kind' => 'error', 'text' => 'Failed: boom', 'more' => ''],
            ['time' => '00:47:19', 'kind' => 'good', 'text' => 'PR opened: https://github.com/owner/app/pull/1', 'more' => ''],
        ], $entries);
    }

    public function test_the_run_page_renders_entries_with_their_kind(): void
    {
        $run = $this->makeRun();
        $run->log('$ npm test');

        $this->get(route('runs.show', $run))
            ->assertSee('class="log-entry log-command"', false)
            ->assertSee('npm test');
    }
}
