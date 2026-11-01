<?php

namespace App\Console\Commands;

use App\Guardrails\Guard;
use App\Guardrails\StackSet;
use App\Models\Run;
use Illuminate\Console\Command;

class GuardTool extends Command
{
    protected $signature = 'foreman:guard {rules : Path to the run\'s rules file}';

    protected $description = 'Claude Code PreToolUse hook. Reads the tool call on stdin and exits 2, with a reason, to block it.';

    public function handle(): int
    {
        $rules = json_decode((string) @file_get_contents($this->argument('rules')), true);
        $hook = json_decode((string) file_get_contents('php://stdin'), true);

        // Exit 1 would let the call through, so a broken hook has to fail closed with 2
        if (! is_array($rules) || ! is_array($hook)) {
            $this->getOutput()->getErrorStyle()->writeln('Foreman guard could not read its rules or the tool call.');

            return 2;
        }

        $guard = new Guard(StackSet::detect($rules['worktree']), $rules['worktree'], $rules['locked_paths'], $rules['allow_lock_files']);
        $reason = $guard->check($hook);

        if ($reason === null) {
            return self::SUCCESS;
        }

        Run::appendLog($rules['log'], "Blocked by guardrail: {$reason}");
        $this->getOutput()->getErrorStyle()->writeln("Blocked by Foreman guardrail: {$reason}");

        return 2;
    }
}
