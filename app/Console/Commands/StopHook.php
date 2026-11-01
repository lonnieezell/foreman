<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\Run;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class StopHook extends Command
{
    /** Stops it turns back. The next one is let through, well before Claude Code's own limit of 8 continuations. */
    public const MAX_BLOCKS = 3;

    protected $signature = 'foreman:stop {rules : Path to the run\'s rules file}';

    protected $description = 'Claude Code Stop hook. Runs the verify script and the test-integrity check, and exits 2 to send the agent back.';

    public function handle(): int
    {
        $rules = json_decode((string) @file_get_contents($this->argument('rules')), true);

        // Exit 2 here would trap the agent in a loop it can't fix, so a broken hook lets it stop (exit 1 is non-blocking)
        if (! is_array($rules)) {
            $this->getOutput()->getErrorStyle()->writeln('Foreman stop hook could not read its rules.');

            return 1;
        }

        $blocks = (int) @file_get_contents($rules['stop_counter']);

        if ($blocks >= self::MAX_BLOCKS) {
            Run::appendLog($rules['log'], 'Stop hook gave up after '.self::MAX_BLOCKS.' blocked stops. The checks still fail, so a human should look.');

            return self::SUCCESS;
        }

        $verify = trim($rules['verify_script']) ?: Repository::defaultVerifyScript($rules['worktree']);
        $failures = array_filter([
            $this->failure('Test-integrity check', [PHP_BINARY, resource_path('guardrails/test-integrity.php'), "--base={$rules['base']}"], $rules['worktree'], 120),
            $verify === '' ? null : $this->failure('Verify script', ['bash', '-ec', $verify], $rules['worktree'], 1500),
        ]);

        if (! $failures) {
            return self::SUCCESS;
        }

        file_put_contents($rules['stop_counter'], (string) ($blocks + 1));
        Run::appendLog($rules['log'], 'Blocked the stop ('.($blocks + 1).' of '.self::MAX_BLOCKS.'): '.implode(' and ', array_keys($failures)).' failed.');

        $this->getOutput()->getErrorStyle()->writeln(
            "You can't stop yet. Fix this, then finish again.\n\n".implode("\n\n", $failures)
        );

        return 2;
    }

    /** The trimmed output when the command fails, or null when it passes. */
    private function failure(string $label, array $command, string $cwd, int $timeout): ?string
    {
        $process = new Process($command, $cwd, timeout: $timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return "{$label} failed: {$e->getMessage()}";
        }

        if ($process->isSuccessful()) {
            return null;
        }

        // Failures are at the end of the output, so keep the tail
        $output = trim((string) preg_replace('/\e\[[\d;]*m/', '', $process->getOutput().$process->getErrorOutput()));

        return "{$label} failed (exit {$process->getExitCode()}):\n".(strlen($output) > 3000 ? '…'.substr($output, -3000) : $output);
    }
}
