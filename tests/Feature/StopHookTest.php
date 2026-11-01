<?php

namespace Tests\Feature;

use App\Console\Commands\StopHook;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StopHookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-stop-'.uniqid();
        File::ensureDirectoryExists("{$this->dir}/work");
        $this->git('init', '-q', '-b', 'main');
        file_put_contents("{$this->dir}/work/composer.json", '{}');
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=t@example.test', 'commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], "{$this->dir}/work"))->mustRun();
    }

    /** Runs the real command, as Claude Code does. @return array{int, string} exit code and stderr */
    private function stop(string $verify, array $rules = []): array
    {
        file_put_contents("{$this->dir}/rules.json", json_encode([
            'worktree' => "{$this->dir}/work",
            'log' => "{$this->dir}/run.log",
            'base' => 'main',
            'verify_script' => $verify,
            'stop_counter' => "{$this->dir}/stops",
            ...$rules,
        ]));

        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:stop', "{$this->dir}/rules.json"], base_path());
        $process->setInput('{"hook_event_name":"Stop"}');
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput()];
    }

    private function log(): string
    {
        return (string) @file_get_contents("{$this->dir}/run.log");
    }

    public function test_passing_checks_let_the_agent_stop(): void
    {
        [$code] = $this->stop('echo all good');

        $this->assertSame(0, $code);
        $this->assertSame('', $this->log());
    }

    public function test_a_failing_verify_script_blocks_with_its_trimmed_output(): void
    {
        [$code, $message] = $this->stop("seq 1 2000\necho 'Tests: 1 failed' >&2\nexit 1");

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Verify script failed (exit 1)', $message);
        $this->assertStringContainsString('Tests: 1 failed', $message);
        $this->assertStringContainsString('2000', $message);
        $this->assertStringNotContainsString("\n1\n", $message, 'only the end of the output is kept');
        $this->assertStringContainsString('Blocked the stop (1 of 3)', $this->log());
    }

    public function test_the_verify_script_runs_in_the_worktree_and_stops_at_the_first_failing_line(): void
    {
        [$code, $message] = $this->stop("test -f composer.json\nfalse\necho never-reached");

        $this->assertSame(2, $code);
        $this->assertStringNotContainsString('never-reached', $message);
    }

    public function test_a_failing_integrity_check_blocks_even_when_the_verify_script_passes(): void
    {
        mkdir("{$this->dir}/work/tests");
        file_put_contents("{$this->dir}/work/tests/ThingTest.php", '<?php // test( assert');
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=t@example.test', 'commit', '-q', '-m', 'add test');
        $this->git('checkout', '-q', '-b', 'agent/x');
        unlink("{$this->dir}/work/tests/ThingTest.php");

        [$code, $message] = $this->stop('true', ['base' => 'HEAD']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Test-integrity check failed', $message);
        $this->assertStringContainsString('Deleted test file tests/ThingTest.php', $message);
        $this->assertStringNotContainsString('Verify script failed', $message);
    }

    public function test_both_failures_are_reported_together(): void
    {
        $this->git('checkout', '-q', '-b', 'agent/x');
        file_put_contents("{$this->dir}/work/phpunit.xml", '<phpunit/>');

        [$code, $message] = $this->stop('echo lint failed; exit 1');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Test-integrity check failed', $message);
        $this->assertStringContainsString('lint failed', $message);
    }

    public function test_an_empty_verify_script_falls_back_to_the_detected_stacks_checks(): void
    {
        // composer.json requires nothing, so the detected check is phpunit, which isn't installed here
        [$code, $message] = $this->stop('  ');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Verify script failed', $message);
        $this->assertStringContainsString('vendor/bin/phpunit', $message);
    }

    public function test_it_gives_up_after_three_blocked_stops(): void
    {
        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame(2, $this->stop('exit 1')[0], "stop {$attempt} should be blocked");
        }

        [$code] = $this->stop('exit 1');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Blocked the stop (3 of '.StopHook::MAX_BLOCKS.')', $this->log());
        $this->assertStringContainsString('Stop hook gave up after 3 blocked stops', $this->log());
        $this->assertSame(3, StopHook::MAX_BLOCKS, 'Claude Code forces a stop after 8 continuations, so stay well below it');
    }

    public function test_passing_does_not_use_up_a_block(): void
    {
        $this->assertSame(2, $this->stop('exit 1')[0]);
        $this->assertSame(0, $this->stop('true')[0]);
        $this->assertSame(2, $this->stop('exit 1')[0]);
        $this->assertSame(2, $this->stop('exit 1')[0]);

        $this->assertSame('3', trim((string) file_get_contents("{$this->dir}/stops")));
        $this->assertSame(0, $this->stop('exit 1')[0], 'the pass in the middle gave nothing back, so this is the fourth stop');
    }

    public function test_unreadable_rules_do_not_trap_the_agent(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:stop', "{$this->dir}/missing.json"], base_path());
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('could not read its rules', $process->getErrorOutput());
    }
}
