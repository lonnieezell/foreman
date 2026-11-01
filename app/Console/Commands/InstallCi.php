<?php

namespace App\Console\Commands;

use App\Guardrails\CiKit;
use App\Guardrails\StackSet;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class InstallCi extends Command
{
    protected $signature = 'foreman:install-ci {path : The local checkout of the repository} {--branch=foreman/ci-kit : The new branch to commit to}';

    protected $description = "Writes Foreman's CI workflow into a repository on a new branch. It never pushes. A human reviews and pushes it.";

    public function handle(): int
    {
        $given = realpath($this->argument('path'));
        $branch = $this->option('branch');

        if ($given === false) {
            return $this->refuse('That path is not a git checkout.');
        }

        $toplevel = $this->git($given, ['rev-parse', '--show-toplevel']);

        if (! $toplevel->successful()) {
            return $this->refuse('That path is not a git checkout.');
        }

        // GitHub only reads workflows at the repo root, so a sub-folder path still writes there
        $path = trim($toplevel->output());

        $stacks = StackSet::detect($path);

        if (! $stacks->stacks) {
            return $this->refuse('No PHP, TypeScript, Rust or Python project found, so there is nothing to write.');
        }

        if (is_file("{$path}/".CiKit::WORKFLOW)) {
            return $this->refuse(CiKit::WORKFLOW.' already exists. Remove it first if you want a fresh copy.');
        }

        $from = trim($this->git($path, ['branch', '--show-current'])->output()) ?: 'the current commit';
        $created = $this->git($path, ['switch', '--create', $branch]);

        if (! $created->successful()) {
            return $this->refuse("Could not create the branch {$branch}: ".trim($created->errorOutput()));
        }

        $files = [CiKit::WORKFLOW => CiKit::yaml($stacks), CiKit::SCRIPT => (string) file_get_contents(resource_path('guardrails/test-integrity.php'))];
        $paths = array_keys($files);

        foreach ($files as $file => $contents) {
            File::ensureDirectoryExists(dirname("{$path}/{$file}"));
            file_put_contents("{$path}/{$file}", $contents);
        }

        $this->git($path, ['add', '--', ...$paths]);
        $commit = $this->git($path, ['commit', '--message', 'ci: add Foreman CI guardrails', '--', ...$paths]);

        if (! $commit->successful()) {
            $this->git($path, ['rm', '--force', '--quiet', '--', ...$paths]);
            $this->git($path, ['switch', '-']);
            $this->git($path, ['branch', '--delete', '--force', $branch]);

            return $this->refuse('Could not commit, so nothing was changed: '.trim($commit->output().$commit->errorOutput()));
        }

        $this->info('Wrote the '.implode(', ', $stacks->names())." job(s) on branch {$branch}, based on {$from}. Nothing was pushed.");
        $this->line("Review it, then run: git push --set-upstream origin {$branch}");

        return self::SUCCESS;
    }

    private function git(string $path, array $args): ProcessResult
    {
        return Process::path($path)->run(['git', ...$args]);
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
