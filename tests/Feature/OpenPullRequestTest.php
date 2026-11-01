<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class OpenPullRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PR = 'https://github.com/owner/app/pull/93';

    /**
     * Opens a PR with `gh pr create` answering as `$create` says, and `gh pr list` finding `$existing`.
     * `$create` is 'ok', 'fails' (exit 1) or 'hangs' (throws, as a timeout does).
     */
    private function open(string $create, string $existing): string
    {
        Process::fake(function (PendingProcess $process) use ($create, $existing) {
            $command = array_slice($process->command, 0, 3);

            if ($command === ['gh', 'pr', 'create']) {
                return match ($create) {
                    'ok' => Process::result("Creating pull request\n".self::PR."\n"),
                    'fails' => Process::result('', 'HTTP 502: Bad Gateway', 1),
                    'hangs' => throw new RuntimeException('The process "gh pr create" exceeded the timeout of 60 seconds.'),
                };
            }

            return $command === ['gh', 'pr', 'list'] ? Process::result($existing === '' ? '' : "{$existing}\n") : Process::result();
        });

        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'base_branch' => 'main',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ]);

        return app(GitHub::class)->openPullRequest($repo->fresh(), 'agent/issue-58', 'Fix', 'Closes #58');
    }

    public function test_it_returns_the_url_gh_prints(): void
    {
        $this->assertSame(self::PR, $this->open('ok', ''));
        Process::assertDidntRun(fn (PendingProcess $p) => array_slice($p->command, 0, 3) === ['gh', 'pr', 'list']);
    }

    public function test_a_create_that_times_out_after_github_opened_the_pr_uses_that_pr(): void
    {
        $this->assertSame(self::PR, $this->open('hangs', self::PR));
        Process::assertRan(fn (PendingProcess $p) => $p->command === [
            'gh', 'pr', 'list', '--head', 'agent/issue-58', '--state', 'open', '--json', 'url', '--jq', '.[0].url', '--repo', 'owner/app',
        ]);
    }

    public function test_a_create_that_fails_after_github_opened_the_pr_uses_that_pr(): void
    {
        $this->assertSame(self::PR, $this->open('fails', self::PR));
    }

    public function test_a_create_that_fails_with_no_pr_on_github_still_fails(): void
    {
        try {
            $this->open('hangs', '');
            $this->fail('Expected the original error');
        } catch (Throwable $e) {
            $this->assertStringContainsString('exceeded the timeout of 60 seconds', $e->getMessage());
        }
    }
}
