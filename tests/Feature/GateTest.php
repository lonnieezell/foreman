<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\Gate;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-gate-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->git('init', '-q', '-b', 'main');
        file_put_contents("{$this->dir}/composer.json", '{}');
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
        (new Process(['git', ...$args], $this->dir))->mustRun();
    }

    public function test_passing_checks_return_no_failures(): void
    {
        $this->assertSame([], Gate::run($this->dir, 'main', 'echo all good'));
    }

    public function test_a_failing_verify_script_is_named_and_keeps_only_the_tail_of_its_output(): void
    {
        $failures = Gate::run($this->dir, 'main', "seq 1 2000\nprintf '\\033[31mred\\033[0m\\n'\nexit 1");

        $this->assertSame(['Verify script'], array_keys($failures));
        $this->assertStringContainsString('Verify script failed (exit 1)', $failures['Verify script']);
        $this->assertStringContainsString('2000', $failures['Verify script']);
        $this->assertStringNotContainsString("\n1\n", $failures['Verify script']);
        $this->assertStringContainsString('red', $failures['Verify script']);
        $this->assertStringNotContainsString("\e[", $failures['Verify script']);
        $this->assertLessThan(3100, strlen($failures['Verify script']));
    }

    public function test_the_verify_script_runs_in_the_folder_with_ci_set(): void
    {
        $this->assertSame([], Gate::run($this->dir, 'main', "test -f composer.json\ntest \"\$CI\" = true"));
    }

    public function test_a_failing_integrity_check_is_named_even_when_the_verify_script_passes(): void
    {
        mkdir("{$this->dir}/tests");
        file_put_contents("{$this->dir}/tests/ThingTest.php", '<?php // test( assert');
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=t@example.test', 'commit', '-q', '-m', 'add test');
        $this->git('checkout', '-q', '-b', 'agent/x');
        unlink("{$this->dir}/tests/ThingTest.php");

        $failures = Gate::run($this->dir, 'HEAD', 'true');

        $this->assertSame(['Test-integrity check'], array_keys($failures));
        $this->assertStringContainsString("Fewer test functions across the changed test files: 1 before, 0 now.\n  tests/ThingTest.php: 1 before, 0 now.", $failures['Test-integrity check']);
    }

    public function test_both_failures_come_back_together(): void
    {
        $this->git('checkout', '-q', '-b', 'agent/x');
        file_put_contents("{$this->dir}/phpunit.xml", '<phpunit/>');

        $this->assertSame(['Test-integrity check', 'Verify script'], array_keys(Gate::run($this->dir, 'main', 'exit 1')));
    }

    public function test_the_checks_drop_the_agents_push_block_but_keep_its_other_git_settings(): void
    {
        $env = ['GIT_CONFIG_COUNT' => '2', 'GIT_CONFIG_KEY_0' => 'remote.origin.pushurl', 'GIT_CONFIG_VALUE_0' => '/nonexistent/x', 'GIT_CONFIG_KEY_1' => 'foreman.kept', 'GIT_CONFIG_VALUE_1' => 'yes'];

        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
        }

        try {
            // Tests that push to a local "origin" pass in the gate and locally, so the Stop hook must not fail them
            $failures = Gate::run($this->dir, 'main', "! git config --get remote.origin.pushurl\ntest \"\$(git config --get foreman.kept)\" = yes");
        } finally {
            foreach (array_keys($env) as $name) {
                putenv($name);
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_an_empty_verify_script_falls_back_to_the_detected_stacks_checks(): void
    {
        $this->assertSame($this->detectedScript(), Gate::verifyScript('  ', $this->dir, 'main'));
        $this->assertSame('make test', Gate::verifyScript(" make test\n", $this->dir, 'main'));
        $this->assertStringContainsString('vendor/bin/phpunit', Gate::run($this->dir, 'main', null)['Verify script']);
    }

    public function test_the_before_hook_runs_ahead_of_each_check_and_can_stop_the_gate(): void
    {
        $calls = 0;
        Gate::run($this->dir, 'main', 'true', before: function () use (&$calls) {
            $calls++;
        });
        $this->assertSame(2, $calls);

        $this->expectException(RuntimeException::class);
        Gate::run($this->dir, 'main', 'true', before: fn () => throw new RuntimeException('Cancelled'));
    }

    public function test_the_progress_hook_runs_after_each_check(): void
    {
        $calls = 0;
        Gate::run($this->dir, 'main', 'true', progress: function () use (&$calls) {
            $calls++;
        });

        $this->assertGreaterThanOrEqual(2, $calls);
    }

    private function detectedScript(): string
    {
        return Repository::defaultVerifyScript($this->dir, 'main');
    }
}
