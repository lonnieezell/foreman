<?php

namespace App\Console\Commands;

use App\Guardrails\CommitFormatter;
use App\Guardrails\StackSet;
use App\Models\Run;
use Illuminate\Console\Command;

class FormatStaged extends Command
{
    protected $signature = 'foreman:format-staged {rules : Path to the run\'s rules file}';

    protected $description = 'Claude Code PreToolUse hook. Formats and re-stages the staged files before a `git commit`, and exits 2 if a formatter fails.';

    public function handle(): int
    {
        $rules = json_decode((string) @file_get_contents($this->argument('rules')), true);
        $hook = json_decode((string) file_get_contents('php://stdin'), true);

        // Exit 1 would let the commit through, so a broken hook has to fail closed with 2
        if (! is_array($rules) || ! is_array($hook)) {
            $this->getOutput()->getErrorStyle()->writeln('Foreman formatter could not read its rules or the tool call.');

            return 2;
        }

        if (($hook['tool_name'] ?? '') !== 'Bash' || ! CommitFormatter::isCommit((string) ($hook['tool_input']['command'] ?? ''))) {
            return self::SUCCESS;
        }

        $failure = (new CommitFormatter(StackSet::detect($rules['worktree']), $rules['worktree']))->format();

        if ($failure === null) {
            return self::SUCCESS;
        }

        Run::appendLog($rules['log'], "Blocked by guardrail: commit blocked, the formatter failed.\n{$failure}");
        $this->getOutput()->getErrorStyle()->writeln("Commit blocked. The formatter failed. Fix this and commit again:\n{$failure}");

        return 2;
    }
}
