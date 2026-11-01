<?php

namespace App\Guardrails;

use App\Support\ForemanEnv;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Formats the staged files with each stack's formatter and stages the result.
 * Used by the PreToolUse hook when the agent runs `git commit`.
 */
class CommitFormatter
{
    public function __construct(private StackSet $stacks, private string $worktree) {}

    public static function isCommit(string $command): bool
    {
        return (bool) preg_match('/'.Guard::COMMAND_START.'git(?:\s+-[\w-]+(?:[ =]\S+)?)*\s+commit\b/', $command);
    }

    /** Returns the formatter's output when it fails, or null when the files are formatted. */
    public function format(): ?string
    {
        $staged = $this->staged();

        foreach ($this->stacks->stacks as $stack) {
            $command = $stack->formatter();
            $files = array_values(array_filter($staged, fn (string $file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $stack->formatExtensions(), true)));

            if ($command === null || $files === [] || ! $this->installed($command)) {
                continue;
            }

            $process = Process::fromShellCommandline($command.' '.implode(' ', array_map('escapeshellarg', $files)), $this->worktree, ForemanEnv::removed());
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                return trim("`{$command}` failed:\n{$process->getOutput()}{$process->getErrorOutput()}");
            }

            $this->git(['add', '--', ...$files]);
        }

        return null;
    }

    /** @return list<string> Staged files that still exist, relative to the worktree root. */
    private function staged(): array
    {
        $names = explode("\0", $this->git(['diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z']));

        return array_values(array_filter($names, fn (string $name) => $name !== '' && is_file("{$this->worktree}/{$name}")));
    }

    /** A formatter the repository doesn't have installed is skipped, not failed. */
    private function installed(string $command): bool
    {
        [$program, $tool] = [...explode(' ', $command), null];

        return match (true) {
            str_contains($program, '/') => is_file("{$this->worktree}/{$program}"),
            $program === 'npx' => is_file("{$this->worktree}/node_modules/.bin/{$tool}"),
            default => (new ExecutableFinder)->find($program) !== null,
        };
    }

    private function git(array $args): string
    {
        $process = new Process(['git', ...$args], $this->worktree);
        $process->run();

        return $process->getOutput();
    }
}
