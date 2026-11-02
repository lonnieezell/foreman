<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Guardrails\RiskTier;
use App\Guardrails\StackSet;
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
            $this->finish($run, RunStatus::NeedsHuman, 'Someone else pushed '.substr($pr['head_sha'], 0, 7).' to the branch. Leaving the PR to you.', 'someone_pushed');

            return;
        }

        if (! $this->settled($run, $pr)) {
            return;
        }

        // Still pending at the timeout counts as outstanding: it hasn't passed
        $checks = array_values(array_filter($pr['checks'], fn (array $c) => $c['state'] !== 'passed'));
        $threads = $pr['threads'];

        if (! $checks && ! $threads) {
            if ($this->autoMerge($run, $pr)) {
                return;
            }

            $this->finish($run, RunStatus::PrReady, 'Checks passed and no bot threads are open.');

            return;
        }

        $maxRounds = config('foreman.pr_watch.max_rounds');

        if ($run->pr_round >= $maxRounds) {
            $this->github->commentOnPullRequest($repo, $run->pr_url, $this->summary($checks, $threads, $maxRounds));
            $this->github->addHumanLabel($repo, $run->issue_number);
            $this->finish($run, RunStatus::NeedsHuman, "Still outstanding after {$maxRounds} rounds. Left a summary on the PR.", 'rounds_ran_out');

            return;
        }

        if ($this->roundsHeld($run)) {
            return;   // try again on the next poll
        }

        $this->startRound($run, $checks, $threads);
    }

    /**
     * Merges a ready PR when the repository opted in and the change is low-risk enough.
     * Gives false when the run should end as PR ready as usual. Any other outcome is handled here.
     */
    private function autoMerge(Run $run, array $pr): bool
    {
        // Read live, not from the run: turning auto-merge off must stop pending merges at once
        $repo = $run->repository()->first();
        $max = $repo->auto_merge_max_tier;

        if (! $max) {
            return false;
        }

        if (Setting::paused()) {
            $held = 'Ready to auto-merge; held while Foreman is paused.';

            if (! str_ends_with(trim($run->tail(1)), $held)) {
                $run->log($held);
            }

            return true;
        }

        // Later commits can change the diff, so rate it again
        $diff = $this->github->pullRequestDiff($repo, $run->pr_url);
        $tier = RiskTier::assess(StackSet::detect(is_dir((string) $run->worktree_path) ? $run->worktree_path : $repo->local_path), $diff)['tier'];
        $before = $run->risk_tier;

        if ($tier !== $before) {
            $run->update(['risk_tier' => $tier]);
            $before === null
                ? $this->github->labelPullRequest($repo, $run->pr_url, "risk:tier-{$tier}")
                : $this->github->swapPullRequestLabel($repo, $run->pr_url, "risk:tier-{$before}", "risk:tier-{$tier}");
        }

        if ($tier > $max) {
            // Rated that high from the start: not an auto-merge case, so it is PR ready as usual
            if ($before === null || $tier <= $before) {
                return false;
            }

            $this->handBack($run, "Tier rose from {$before} to {$tier} after fix-up rounds. Foreman won't auto-merge it. Over to you.",
                "Tier rose from {$before} to {$tier} after fix-up rounds. Left the PR to you.", 'tier_rose');

            return true;
        }

        $blocks = array_filter([
            $pr['human_threads'] ?? 0 ? 'a person has an open review thread' : null,
            ($pr['changes_requested'] ?? false) ? 'a person asked for changes' : null,
            ($pr['is_draft'] ?? false) ? 'the PR is a draft' : null,
        ]);

        if ($blocks) {
            $why = implode(', ', $blocks);
            $this->handBack($run, 'Foreman won\'t auto-merge: '.$why.'. Over to you.', "Not auto-merging: {$why}.", 'human_review');

            return true;
        }

        $this->github->commentOnPullRequest($repo, $run->pr_url, "Auto-merged by Foreman: tier {$tier}, checks passed, no open threads.");

        if ($error = $this->github->mergePullRequest($repo, $run->pr_url, $repo->merge_method, $run->head_sha)) {
            $this->handBack($run, "Auto-merge failed: {$error}", "The merge failed. gh said: {$error}", 'merge_failed');

            return true;
        }

        $this->github->labelPullRequest($repo, $run->pr_url, 'auto-merged');
        $this->finish($run, RunStatus::Merged, sprintf('Auto-merged (%s) at %s, tier %d.', $repo->merge_method, substr($run->head_sha, 0, 7), $tier));

        return true;
    }

    /** Comments on the PR, flags the issue for a human and ends the run as Needs you. */
    private function handBack(Run $run, string $comment, string $log, string $stopReason): void
    {
        $this->github->commentOnPullRequest($run->repository, $run->pr_url, $comment);
        $this->github->addHumanLabel($run->repository, $run->issue_number);
        $this->finish($run, RunStatus::NeedsHuman, $log, $stopReason);
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

        // No bot has ever posted on this PR, so after a first look there is nothing to wait for
        if (! $pr['bot_activity_at']) {
            return $run->watch_started_at->copy()->addMinutes(config('foreman.pr_watch.first_look_minutes'))->isPast();
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
            'effort' => $run->repository->effort,
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

    private function finish(Run $run, RunStatus $status, string $message, ?string $stopReason = null): void
    {
        $run->moveTo($status, ['stop_reason' => $stopReason, 'finished_at' => now()]);
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
