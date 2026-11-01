<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FormatStagedTest extends TestCase
{
    private string $dir;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-format-'.uniqid();
        $this->work = "{$this->dir}/work";
        File::ensureDirectoryExists("{$this->work}/vendor/bin");
        file_put_contents("{$this->work}/composer.json", json_encode(['require-dev' => ['laravel/pint' => '^1.0']]));

        foreach ([['init'], ['config', 'user.email', 'a@b.test'], ['config', 'user.name', 'A']] as $args) {
            (new Process(['git', ...$args], $this->work))->mustRun();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** A stand-in for Pint, so the test doesn't depend on what is installed. */
    private function fakePint(string $body): void
    {
        file_put_contents("{$this->work}/vendor/bin/pint", "#!/bin/sh\n{$body}\n");
        chmod("{$this->work}/vendor/bin/pint", 0755);
    }

    private function stage(string $file, string $content): void
    {
        file_put_contents("{$this->work}/{$file}", $content);
        (new Process(['git', 'add', $file], $this->work))->mustRun();
    }

    /** @return array{int, string} exit code and stderr */
    private function hook(string $command, string $tool = 'Bash'): array
    {
        file_put_contents("{$this->dir}/rules.json", json_encode(['worktree' => $this->work, 'log' => "{$this->dir}/run.log"]));

        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:format-staged', "{$this->dir}/rules.json"], base_path());
        $process->setInput(json_encode(['tool_name' => $tool, 'tool_input' => ['command' => $command]]));
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput()];
    }

    private function stagedContent(string $file): string
    {
        $process = new Process(['git', 'show', ":{$file}"], $this->work);
        $process->mustRun();

        return $process->getOutput();
    }

    public function test_a_staged_unformatted_file_is_formatted_and_restaged_before_the_commit(): void
    {
        $this->fakePint('for f in "$@"; do printf "<?php // formatted\n" > "$f"; done');
        $this->stage('a.php', '<?php   echo  1;');

        [$code, $stderr] = $this->hook('git add . && git commit -m "feat: x"');

        $this->assertSame(0, $code, $stderr);
        $this->assertSame("<?php // formatted\n", file_get_contents("{$this->work}/a.php"));
        $this->assertSame("<?php // formatted\n", $this->stagedContent('a.php'));
    }

    public function test_only_files_the_stack_handles_are_passed_to_its_formatter(): void
    {
        $this->fakePint('echo "$@" > "$(dirname "$0")/args"');
        $this->stage('a.php', '<?php');
        $this->stage('notes.txt', 'hi');

        [$code, $stderr] = $this->hook('git commit -m x');

        $this->assertSame(0, $code, $stderr);
        $this->assertSame("a.php\n", file_get_contents("{$this->work}/vendor/bin/args"));
    }

    public function test_a_formatter_failure_blocks_the_commit_with_exit_2_and_shows_the_output(): void
    {
        $this->fakePint('echo "syntax error, unexpected token" >&2; exit 1');
        $this->stage('a.php', '<?php {');

        [$code, $stderr] = $this->hook('git commit -m x');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('syntax error, unexpected token', $stderr);
        $this->assertStringContainsString('syntax error, unexpected token', file_get_contents("{$this->dir}/run.log"));
    }

    public function test_a_stack_with_no_formatter_installed_is_skipped(): void
    {
        $this->stage('a.php', '<?php   echo  1;');

        [$code, $stderr] = $this->hook('git commit -m x');

        $this->assertSame(0, $code, $stderr);
        $this->assertSame('<?php   echo  1;', $this->stagedContent('a.php'));
    }

    public function test_commands_that_are_not_a_commit_are_left_alone(): void
    {
        $this->fakePint('exit 1');
        $this->stage('a.php', '<?php');

        $this->assertSame(0, $this->hook('git status')[0]);
        $this->assertSame(0, $this->hook('echo "git commit"')[0]);
        $this->assertSame(0, $this->hook('git commit -m x', 'Edit')[0]);
    }

    public function test_it_fails_closed_when_it_cannot_read_its_rules(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'foreman:format-staged', "{$this->dir}/missing.json"], base_path());
        $process->setInput('{}');
        $process->run();

        $this->assertSame(2, $process->getExitCode());
    }
}
