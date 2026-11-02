<?php

namespace App\Services;

use App\Models\Repository;
use App\Support\ForemanEnv;
use Closure;
use Illuminate\Support\Facades\Process;
use Throwable;

/** The checks Foreman gates a change on: the test-integrity script and the verify script. ProcessRun and the Stop hook both run them. */
class Gate
{
    /** The repository's verify script, or the default for the worktree's stacks when it has none. */
    public static function verifyScript(?string $configured, string $worktree, string $base): string
    {
        return trim((string) $configured) ?: Repository::defaultVerifyScript($worktree, $base);
    }

    /**
     * Runs both checks in the worktree and returns the failure text of each one that fails, keyed by its label.
     * `$before` runs ahead of each check, so the caller can stop the gate by throwing.
     * `$progress` runs on the checks' output and after each check, so the caller can keep a heartbeat fresh.
     *
     * @return array<string,string>
     */
    public static function run(string $worktree, string $base, ?string $verifyScript, ?Closure $before = null, ?Closure $progress = null): array
    {
        $verify = self::verifyScript($verifyScript, $worktree, $base);

        return array_filter([
            'Test-integrity check' => self::check($worktree, 'Test-integrity check', [PHP_BINARY, resource_path('guardrails/test-integrity.php'), "--base={$base}"], 120, $before, $progress),
            'Verify script' => $verify === '' ? null : self::check($worktree, 'Verify script', ['bash', '-ec', $verify], 1500, $before, $progress),
        ]);
    }

    /**
     * The inherited `GIT_CONFIG_*` settings without the agent's push block. Tests that push to a local "origin"
     * pass in ProcessRun's gate and on a developer's machine, so the Stop hook must not fail them. The other
     * settings, such as the empty credential helper, stay.
     *
     * @return array<string,string|false>
     */
    private static function withoutPushBlock(): array
    {
        $env = [];
        $kept = 0;

        for ($i = 0; $i < (int) getenv('GIT_CONFIG_COUNT'); $i++) {
            [$key, $value] = [getenv("GIT_CONFIG_KEY_{$i}"), getenv("GIT_CONFIG_VALUE_{$i}")];
            $env += ["GIT_CONFIG_KEY_{$i}" => false, "GIT_CONFIG_VALUE_{$i}" => false];

            if ($key !== 'remote.origin.pushurl') {
                $env["GIT_CONFIG_KEY_{$kept}"] = $key;
                $env["GIT_CONFIG_VALUE_{$kept}"] = $value;
                $kept++;
            }
        }

        return $env ? [...$env, 'GIT_CONFIG_COUNT' => (string) $kept] : [];
    }

    /** The failure text when the command fails, or null when it passes. */
    private static function check(string $worktree, string $label, array $command, int $timeout, ?Closure $before, ?Closure $progress): ?string
    {
        if ($before) {
            $before();
        }

        try {
            // CI keeps test runners such as Jest and react-scripts out of watch mode
            $process = Process::path($worktree)->env([...ForemanEnv::removed(), ...self::withoutPushBlock(), 'CI' => 'true'])->timeout($timeout)->run($command, $progress ? fn () => $progress() : null);
        } catch (Throwable $e) {
            return "{$label} failed: {$e->getMessage()}";
        } finally {
            if ($progress) {
                $progress();
            }
        }

        if ($process->successful()) {
            return null;
        }

        // Failures are at the end of the output, so keep the tail
        $output = trim((string) preg_replace('/\e\[[\d;]*m/', '', $process->output().$process->errorOutput()));

        return "{$label} failed (exit {$process->exitCode()}):\n".(strlen($output) > 3000 ? '…'.substr($output, -3000) : $output);
    }
}
