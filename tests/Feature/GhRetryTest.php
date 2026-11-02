<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class GhRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<PendingProcess> */
    private array $ran = [];

    private function repo(): Repository
    {
        return Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'base_branch' => 'main',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ])->fresh();
    }

    /** A timeout as Laravel's Process throws it, with `$stderr` as the output gh wrote before it was killed. */
    private static function timeout(string $stderr = ''): ProcessTimedOutException
    {
        return new ProcessTimedOutException(
            new SymfonyTimeoutException(new SymfonyProcess(['gh']), SymfonyTimeoutException::TYPE_GENERAL),
            Process::result('', $stderr),
        );
    }

    /** gh hangs for the first `$hangs` calls, then answers with `$output`. */
    private function fake(int $hangs, string $output = '', string $stderr = ''): void
    {
        Process::fake(function (PendingProcess $process) use (&$hangs, $output, $stderr) {
            $this->ran[] = $process;

            if ($hangs-- > 0) {
                throw self::timeout($stderr);
            }

            return Process::result($output);
        });
    }

    public function test_a_read_that_hangs_once_is_tried_again_with_api_debug_output(): void
    {
        $this->fake(1, json_encode(['labels' => [['name' => 'bug']]]));

        $this->assertSame(['bug'], app(GitHub::class)->labels($this->repo(), 7));
        $this->assertCount(2, $this->ran);
        $this->assertSame(30, $this->ran[0]->timeout);
        $this->assertArrayNotHasKey('GH_DEBUG', $this->ran[0]->environment);
        $this->assertSame('api', $this->ran[1]->environment['GH_DEBUG']);
    }

    public function test_a_graphql_query_counts_as_a_read(): void
    {
        $this->fake(1, json_encode(['data' => ['repository' => ['issue' => ['comments' => ['nodes' => []]]]]]));

        app(GitHub::class)->trustedComments($this->repo(), 7, null);

        $this->assertCount(2, $this->ran);
    }

    public function test_a_read_that_hangs_twice_fails_and_logs_the_end_of_the_debug_output(): void
    {
        Log::spy();
        $this->fake(2, stderr: "* Request at 2026-11-02\n> POST https://api.github.com/graphql\n> Authorization: token ghp_abc123SECRET\n");

        try {
            app(GitHub::class)->labels($this->repo(), 7);
            $this->fail('Expected the timeout');
        } catch (ProcessTimedOutException) {
        }

        $this->assertCount(2, $this->ran);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'gh timed out twice'
            && str_contains($context['debug'], '> POST https://api.github.com/graphql')
            && ! str_contains($context['debug'], 'ghp_abc123SECRET'));
    }

    public function test_writes_are_never_tried_twice(): void
    {
        $github = app(GitHub::class);
        $repo = $this->repo();

        foreach ([
            fn () => $github->addHumanLabel($repo, 7),
            fn () => $github->replyToThread($repo, 'T_1', 'Fixed'),
        ] as $write) {
            $this->ran = [];
            $this->fake(1);

            try {
                $write();
                $this->fail('Expected the timeout');
            } catch (ProcessTimedOutException) {
            }

            $this->assertCount(1, $this->ran);
            $this->assertSame(60, $this->ran[0]->timeout);
        }
    }

    public function test_a_retry_that_fails_reports_gh_s_own_error_without_the_debug_output(): void
    {
        Process::fake(function (PendingProcess $process) {
            $this->ran[] = $process;

            return count($this->ran) === 1
                ? throw self::timeout()
                : Process::result('', "* Request at 2026-11-02\n> GET https://api.github.com/x\nHTTP 502: Bad Gateway", 1);
        });

        try {
            app(GitHub::class)->sanitisedIssue($this->repo(), 7);
            $this->fail('Expected the failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 502: Bad Gateway', $e->getMessage());
            $this->assertStringNotContainsString('Request at', $e->getMessage());
        }
    }
}
