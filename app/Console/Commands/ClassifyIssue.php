<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\GitHub;
use App\Services\StepPicker;
use Illuminate\Console\Command;

class ClassifyIssue extends Command
{
    protected $signature = 'foreman:classify
        {--repo= : The repository id}
        {--issue= : The issue number}';

    protected $description = 'Show the difficulty and step Foreman would pick for an issue, without starting a run. Spends one Haiku call unless the issue has a difficulty label.';

    public function handle(GitHub $github): int
    {
        $repo = Repository::findOrFail($this->option('repo'));
        $issue = (int) $this->option('issue');

        $picker = new StepPicker($repo, $github, fn (string $message) => $this->line($message));
        $this->info($picker->pick($issue, $github->labels($repo, $issue))->describe());

        if (! $repo->isAuto()) {
            $this->line("This repository is in Fixed mode, so its runs use {$repo->modelLabel()} instead.");
        }

        return self::SUCCESS;
    }
}
