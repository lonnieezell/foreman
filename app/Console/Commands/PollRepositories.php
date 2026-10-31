<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\PrWatcher;
use App\Services\Usage;
use Illuminate\Console\Command;
use Throwable;

class PollRepositories extends Command
{
    protected $signature = 'foreman:poll
        {--repo= : Only check this repository id}
        {--force : Ignore the poll interval}';

    protected $description = 'Check enabled repositories for ready issues and start runs. Spends no tokens unless work is found.';

    public function handle(GitHub $github, Usage $usage, PrWatcher $watcher): int
    {
        Setting::put('last_poll_heartbeat', now()->toIso8601String());

        $this->reapStaleRuns($github);

        // Before the pause check: watching costs nothing, and rounds check the pause themselves
        $this->watchPullRequests($watcher);

        if (Setting::paused()) {
            $this->line('Foreman is paused.');

            return self::SUCCESS;
        }

        if (Setting::globalBudgetReached()) {
            $this->line('Global daily budget reached.');

            return self::SUCCESS;
        }

        $repos = Repository::query()
            ->where('enabled', true)
            ->when($this->option('repo'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($repos as $repo) {
            if (! $this->option('force') && ! $repo->dueForPoll()) {
                continue;
            }

            $repo->update(['last_polled_at' => now()]);

            $slots = $repo->freeSlots();
            if ($slots === 0 || $repo->budgetReachedToday()) {
                continue;
            }

            try {
                $issues = $github->readyIssues($repo, $slots);
                $repo->update(['last_error' => null]);
            } catch (Throwable $e) {
                $repo->update(['last_error' => trim($e->getMessage())]);
                continue;
            }

            // Only now, so a profile with nothing to do is never probed
            if ($issues && ! $usage->allows($repo)) {
                $this->line("Holding {$repo->name}: ".$usage->holdReason($repo->profileName()));
                continue;
            }

            foreach ($issues as $issue) {
                $this->startRun($github, $repo, $issue);
            }
        }

        return self::SUCCESS;
    }

    private function startRun(GitHub $github, Repository $repo, array $issue): void
    {
        try {
            $github->claim($repo, $issue['number']);
        } catch (Throwable $e) {
            $repo->update(['last_error' => "Couldn’t claim #{$issue['number']}: ".trim($e->getMessage())]);

            return;
        }

        $run = $repo->runs()->create([
            'issue_number' => $issue['number'],
            'issue_title' => $issue['title'],
            'issue_url' => $issue['url'],
            'branch' => "agent/issue-{$issue['number']}",
            'status' => RunStatus::Queued,
            'model' => $repo->model,
        ]);

        $run->log("Claimed #{$issue['number']}: {$issue['title']}");
        $this->info("Started run {$run->id} for {$repo->github_repo}#{$issue['number']}");

        ProcessRun::dispatch($run)->onQueue('runs');
    }

    private function watchPullRequests(PrWatcher $watcher): void
    {
        Run::query()
            ->where('status', RunStatus::WatchingPr)
            ->when($this->option('repo'), fn ($q, $id) => $q->where('repository_id', $id))
            ->each(function (Run $run) use ($watcher) {
                try {
                    $watcher->check($run);
                } catch (Throwable $e) {
                    $run->log('Couldn’t check the PR: '.str(trim($e->getMessage()))->limit(300));
                }
            });
    }

    /** Runs whose worker died (laptop closed, process killed) stop sending heartbeats. */
    private function reapStaleRuns(GitHub $github): void
    {
        Run::query()
            ->whereIn('status', [RunStatus::Preparing, RunStatus::Working, RunStatus::Checking])
            ->where('heartbeat_at', '<', now()->subMinutes(5))
            ->each(function (Run $run) use ($github) {
                $run->moveTo(RunStatus::Failed, [
                    'error' => 'The worker stopped responding. It was probably restarted mid-run.',
                    'finished_at' => now(),
                ]);
                $run->log('Marked as failed: no heartbeat for 5 minutes.');
                $github->release($run->repository, $run->issue_number);
            });
    }
}
