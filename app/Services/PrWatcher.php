<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Jobs\ProcessRun;
use App\Models\Run;
use App\Models\Setting;

/**
 * Watches an open PR between agent runs: waits for CI and review bots to settle,
 * then ends the run or queues a review round. Only calls `gh`, so it spends no tokens.
 */
class PrWatcher
{
    public function __construct(private GitHub $github, private Usage $usage) {}

    public function check(Run $run): void
    {
        $repo = $run->repository;
        $pr = $this->github->pullRequest($repo, $run->pr_url);

        if ($pr['state'] === 'MERGED') {
            $this->finish($run, RunStatus::PrReady, 'PR merged.');

            return;
        }

        if ($pr['state'] === 'CLOSED') {
            $this->finish($run, RunStatus::Cancelled, 'PR closed without merging.');

            return;
        }

        if ($pr['head_sha'] !== $run->head_sha) {
            $this->finish($run, RunStatus::NeedsHuman, 'Someone else pushed '.substr($pr['head_sha'], 0, 7).' to the branch. Leaving the PR to you.');

            return;
        }

        if (! $this->settled($run, $pr)) {
            return;
        }

        // Still pending at the timeout counts as outstanding: it hasn't passed
        $checks = array_values(array_filter($pr['checks'], fn (array $c) => $c['state'] !== 'passed'));
        $threads = $pr['threads'];

        if (! $checks && ! $threads) {
            $this->finish($run, RunStatus::PrReady, 'Checks passed and no bot threads are open.');

            return;
        }

        $maxRounds = config('foreman.pr_watch.max_rounds');

        if ($run->pr_round >= $maxRounds) {
            $this->github->commentOnPullRequest($repo, $run->pr_url, $this->summary($checks, $threads, $maxRounds));
            $this->github->addHumanLabel($repo, $run->issue_number);
            $this->finish($run, RunStatus::NeedsHuman, "Still outstanding after {$maxRounds} rounds. Left a summary on the PR.");

            return;
        }

        if ($this->roundsHeld($run)) {
            return;   // try again on the next poll
        }

        $this->startRound($run, $checks, $threads);
    }

    /** Nothing pending and the bots quiet for a while, or the timeout has passed. */
    private function settled(Run $run, array $pr): bool
    {
        if ($run->watch_started_at->addMinutes(config('foreman.pr_watch.timeout_minutes'))->isPast()) {
            return true;
        }

        if (collect($pr['checks'])->contains('state', 'pending')) {
            return false;
        }

        $quietSince = $pr['bot_activity_at'] && $pr['bot_activity_at']->greaterThan($run->watch_started_at)
            ? $pr['bot_activity_at']
            : $run->watch_started_at;

        return $quietSince->copy()->addMinutes(config('foreman.pr_watch.quiet_minutes'))->isPast();
    }

    /** Rounds spend tokens, so they wait on the same things new issues do. */
    private function roundsHeld(Run $run): bool
    {
        $repo = $run->repository;

        return Setting::paused()
            || Setting::globalBudgetReached()
            || $repo->budgetReachedToday()
            || $repo->freeSlots() === 0
            || ! $this->usage->allows($repo);
    }

    private function startRound(Run $run, array $checks, array $threads): void
    {
        $round = $run->repository->runs()->create([
            'issue_number' => $run->issue_number,
            'issue_title' => $run->issue_title,
            'issue_url' => $run->issue_url,
            'branch' => $run->branch,
            'status' => RunStatus::Queued,
            'model' => $run->repository->model,
            'worktree_path' => $run->worktree_path,
            'resume_session_id' => $run->session_id,
            'previous_run_id' => $run->id,
            'pr_url' => $run->pr_url,
            'pr_round' => $run->pr_round + 1,
            'head_sha' => $run->head_sha,
            'pr_feedback' => ['checks' => $checks, 'threads' => $threads],
        ]);

        $run->moveTo(RunStatus::PrOpened);
        $run->log("Handed over to round {$round->pr_round} (run {$round->id}).");
        $round->log(sprintf('Round %d: %d checks not passing, %d bot threads open.', $round->pr_round, count($checks), count($threads)));

        ProcessRun::dispatch($round)->onQueue('runs');
    }

    private function finish(Run $run, RunStatus $status, string $message): void
    {
        $run->moveTo($status);
        $run->log($message);
        $run->removeWorktree();
    }

    private function summary(array $checks, array $threads, int $maxRounds): string
    {
        $lines = ["Foreman made {$maxRounds} rounds of fixes on this PR and these are still outstanding:", ''];

        foreach ($checks as $check) {
            $lines[] = "- Check **{$check['name']}** is ".($check['state'] === 'failed' ? 'failing' : 'still running').($check['url'] ? " ([details]({$check['url']}))" : '');
        }

        foreach ($threads as $thread) {
            $lines[] = "- [{$thread['author']} on `{$thread['path']}`]({$thread['url']})";
        }

        return implode("\n", [...$lines, '', 'Over to you.']);
    }
}
