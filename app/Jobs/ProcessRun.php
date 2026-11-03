<?php

namespace App\Jobs;

use App\Enums\Outcome;
use App\Enums\RunStatus;
use App\Guardrails\Guard;
use App\Guardrails\RiskTier;
use App\Guardrails\StackSet;
use App\Models\Run;
use App\Services\AgentOutcome;
use App\Services\AgentReport;
use App\Services\Claude\AgentResult;
use App\Services\Claude\Claude;
use App\Services\Gate;
use App\Services\GitHub;
use App\Services\JudgeReply;
use App\Services\ScopeJudge;
use App\Services\ScopeVerdict;
use App\Services\Worktree;
use App\Support\ForemanEnv;
use App\Support\IssueSanitiser;
use App\Support\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ProcessRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;   // we enforce max_minutes ourselves so we can clean up

    private bool $allowLockFiles = false;

    /** @var list<string> Subjects of the commits the base merge brought into a continuing run's branch. */
    private array $mergedFromBase = [];

    public function __construct(public Run $run) {}

    public function handle(GitHub $github): void
    {
        $run = $this->run->fresh();
        $repo = $run->repository;

        // Cancelled while it was waiting for a worker
        if ($run->status !== RunStatus::Queued) {
            return;
        }

        $run->moveTo(RunStatus::Preparing, ['started_at' => now(), 'heartbeat_at' => now()]);

        try {
            if ($run->resume_session_id && $run->worktree_path && File::isDirectory($run->worktree_path)) {
                $worktree = $run->worktree_path;   // continuing: keep the agent's work in progress
                $this->catchUpWithBase($run, fetch: true);
            } else {
                $worktree = $this->prepareWorktree($run);
            }

            // Clear the last run's result and verdicts, and keep them out of git
            (new AgentReport($worktree))->clear();
            $this->worktree($run)->excludeFromGit('.foreman/');

            $this->allowLockFiles = in_array('dependencies', $github->labels($repo, $run->issue_number), true);
            $agent = $this->runAgent($run, $worktree, $github);
            $this->settle($run, $github, $agent);
        } catch (Throwable $e) {
            $run->refresh();
            $cancelled = $run->cancel_requested;

            $run->moveTo($cancelled ? RunStatus::Cancelled : RunStatus::Failed, [
                'error' => $cancelled ? null : $e->getMessage(),
                'finished_at' => now(),
            ]);
            $run->log($cancelled ? 'Cancelled.' : 'Failed: '.$e->getMessage());

            if (! $cancelled) {
                $quoted = collect(explode("\n", $e->getMessage()))->map(fn ($line) => rtrim("> {$line}"))->implode("\n");
                $github->flagForHuman($repo, $run->issue_number,
                    "The Foreman run for this issue failed before finishing:\n\n{$quoted}\n\nThe worktree was kept at `{$run->worktree_path}` for inspection.");
            }
        } finally {
            $github->release($repo, $run->issue_number);
        }
    }

    private function prepareWorktree(Run $run): string
    {
        $repo = $run->repository;
        $worktree = $repo->worktreePath($run->issue_number);

        // Continuing without a worktree: rebuild from what was pushed, never reset the branch to base
        $pushed = $this->worktree($run, $worktree)->prepare(continuing: (bool) ($run->resume_session_id || $run->pr_round));

        $run->update(['worktree_path' => $worktree]);

        // Before the setup script, so dependencies the base branch added get installed
        if ($pushed) {
            $this->catchUpWithBase($run, fetch: false);
        }

        $this->step($run, 'Running setup script', ['bash', '-ec', $repo->setup_script], $worktree, timeout: 900, env: ForemanEnv::removed());

        return $worktree;
    }

    /**
     * Merge the latest base branch into a continuing run's branch, so fixes that landed there reach it.
     * Any failure leaves the branch as it was and the run goes on: the gate still catches what the base would have fixed.
     */
    private function catchUpWithBase(Run $run, bool $fetch): void
    {
        $this->guardCancel($run);

        $this->mergedFromBase = $this->worktree($run)->catchUpWithBase($fetch, fn (string $message) => $run->log($message));
    }

    /** The run's worktree, with its steps logged, cancellable, and counted as signs of life. */
    private function worktree(Run $run, ?string $path = null): Worktree
    {
        return $run->worktree($path)->tracking(
            function (string $label) use ($run) {
                $run->log($label);
                $this->guardCancel($run);
            },
            fn () => $run->update(['heartbeat_at' => now()]),
        );
    }

    private function step(Run $run, string $label, array $command, string $cwd, int $timeout = 300, array $env = []): void
    {
        $run->log($label);
        $this->guardCancel($run);

        $result = Process::path($cwd)->env($env)->timeout($timeout)->run($command);
        $run->update(['heartbeat_at' => now()]);

        if ($result->failed()) {
            $output = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException("{$label} failed: ".str($output)->limit(500));
        }
    }

    /**
     * @param  string|null  $followUp  A review pass's prompt. It resumes this run's own session, and its usage adds to the run's.
     */
    private function runAgent(Run $run, string $worktree, GitHub $github, ?string $followUp = null): AgentResult
    {
        $repo = $run->repository;
        $budget = $repo->max_budget_per_run;

        if ($followUp === null) {
            $run->moveTo(RunStatus::Working, ['claude_profile' => $repo->profileName()]);
            $effort = $run->effort ? "{$run->effort} effort, " : '';
            $run->log("Starting Claude ({$run->model}, {$effort}{$repo->profileName()} profile, max {$repo->max_turns} turns, {$repo->max_minutes} min)");
        } elseif ($budget !== null) {
            // The spend cap is per run, so a pass gets what the run has left
            $budget = round((float) $budget - (float) $run->cost_usd, 2);

            if ($budget <= 0) {
                throw new RuntimeException("The run used up its spend cap of \${$repo->max_budget_per_run} before Foreman could resume the agent.");
            }
        }

        $prompt = match (true) {
            $followUp !== null => $followUp,
            (bool) $run->pr_round => $this->roundPrompt($run, $github),
            (bool) $run->resume_session_id => $this->resumePrompt($run, $github),
            default => $repo->renderPrompt($run, $github->sanitisedIssue($repo, $run->issue_number)),
        };

        // Same conversation, so the agent doesn't re-explore the codebase
        $resume = $followUp !== null ? $run->session_id : $run->resume_session_id;

        $agent = app(Claude::class)->agent($run, $worktree, $prompt, $resume, $budget, $this->allowLockFiles);
        $usage = $agent->usage();

        if ($followUp !== null) {
            foreach ($usage as $column => $value) {
                $usage[$column] = $value === null ? $run->{$column} : $value + $run->{$column};
            }
        }

        $run->update([...$usage, 'session_id' => $agent->sessionId ?? $run->session_id]);

        return $agent;
    }

    /** Act on the agent's result file, but push only what passes Foreman's own checks. */
    private function settle(Run $run, GitHub $github, AgentResult $agent): void
    {
        if ($run->pr_round) {
            $this->settleRound($run, $github);

            return;
        }

        $repo = $run->repository;

        if ($this->prResult($run, $github, $agent) === null || $this->review($run, $github) === null) {
            return;
        }

        $agent = $this->reviewPass($run, $github);
        $issue = $github->sanitisedIssue($repo, $run->issue_number);

        // The judge goes before the second gate, so a veto doesn't throw a gate run away
        foreach ([false, true] as $corrected) {
            $result = $this->prResult($run, $github, $agent);

            if ($result === null) {
                return;
            }

            // Only the correction round's answer goes to the judge: it is the one written after a veto
            $verdict = $this->judge($run, $issue, $corrected ? $result->judgeReply : null);

            // No usable answer still goes on to the gate, and opens a draft if it passes
            if ($verdict === null || $verdict->passes()) {
                break;
            }

            if ($corrected) {
                // Missing work is easy to spot in review, so it opens a draft if the gate passes
                if ($verdict->onlyMissesWork()) {
                    break;
                }

                $answer = $result->judgeReply === null
                    ? "The agent's PR body:\n\n".self::quote((string) str($result->body)->limit(2000))
                    : self::judgeAnswer($result->judgeReply);

                $this->handToHuman($run, $github,
                    "Foreman's scope judge vetoed this change twice, once before and once after a correction round. Nothing was pushed, and the branch is still in the worktree.\n\n".self::quote($verdict->describe())."\n\n{$answer}",
                    "Scope judge vetoed the change again: {$verdict->reason} Flagged the issue for a human.", 'scope_vetoed');

                return;
            }

            $run->log("Scope judge vetoed the change: {$verdict->reason} Sending the agent back for one correction round.");
            $agent = $this->runPass($run, $github, 'The correction round', Prompt::render('scope-correction', [
                'base' => $this->worktree($run)->baseRef(),
                'verdict' => $verdict->describe(),
            ]));
        }

        $gate = $this->review($run, $github);

        if ($gate === null) {
            return;
        }

        ['footer' => $footer, 'oversized' => $oversized] = $gate;
        $labels = $oversized ? ['size:large'] : [];

        if ($verdict === null) {
            $run->log('Scope judge gave no usable answer twice. Opening the PR as a draft.');
            $footer .= "\n- Scope judge: no usable answer twice, so the change was not checked against the issue. Opened as a draft.";
            $labels[] = 'scope:unchecked';
        } elseif (! $verdict->passes()) {
            $run->log("Scope judge vetoed the change again, only for missing work: {$verdict->reason} Opening the PR as a draft.");
            $footer .= "\n- Scope judge: vetoed twice, only for missing work, so opened as a draft. {$verdict->reason}";
            $footer .= $result->judgeReply === null ? '' : "\n\n".self::judgeAnswer($result->judgeReply);
            $labels[] = 'scope:incomplete';
        } else {
            $passed = $corrected ? 'passed after one correction round' : 'passed';
            $run->log("Scope judge {$passed}: {$verdict->reason}");
            $footer .= "\n- Scope judge: {$passed}. {$verdict->reason}";
        }

        $this->push($run);

        $body = str_starts_with($result->body, "Closes #{$run->issue_number}") ? $result->body : "Closes #{$run->issue_number}\n\n{$result->body}";
        $body .= "\n\n{$footer}";
        $prUrl = $github->openPullRequest($repo, $run->branch, $result->title, $body, draft: $labels !== []);

        foreach (["risk:tier-{$run->risk_tier}", ...$labels] as $label) {
            $github->labelPullRequest($repo, $prUrl, $label);
        }

        if ($repo->watch_prs) {
            $this->watch($run, $github->pullRequest($repo, $prUrl)['head_sha'], ['pr_url' => $prUrl]);
            $run->log("PR opened: {$prUrl}. Watching it for checks and review bots.");

            return;
        }

        $run->moveTo(RunStatus::PrOpened, ['pr_url' => $prUrl, 'finished_at' => now()]);
        $run->log("PR opened: {$prUrl}");
        $run->removeWorktree();
    }

    /** The agent's `pr` outcome. Any other outcome is handed to a human and gives null; an unreadable result throws. */
    private function prResult(Run $run, GitHub $github, AgentResult $agent): ?AgentOutcome
    {
        $result = (new AgentReport($run->worktree_path))->outcome();

        if ($result === null) {
            $reason = $agent->isError()
                ? 'Claude stopped with an error ('.($agent->subtype() ?? 'unknown').').'
                : 'The agent finished without a readable result file ('.AgentReport::RESULT_FILE.').';

            // Its last message usually says why, so keep it where a human will see it
            $last = $agent->message();

            throw new RuntimeException($last === '' ? $reason : "{$reason} Its last message:\n\n".str($last)->limit(2000));
        }

        if ($result->kind !== Outcome::Pr) {
            $github->flagForHuman($run->repository, $run->issue_number, $result->comment);
            $run->moveTo(RunStatus::NeedsHuman, [
                'finished_at' => now(),
                'stop_reason' => $result->kind === Outcome::AlreadyDone ? 'already_done' : 'agent_asked',
            ]);
            $run->log($result->kind === Outcome::AlreadyDone
                ? 'Agent says the issue is already done. Flagged it for a human.'
                : "Agent asked for a human ({$result->reason}).");

            return null;
        }

        return $result;
    }

    /**
     * Rate the change's risk from its diff, then resume the agent's session to review it as deep as the tier calls for:
     * `/code-review` and `/simplify`, plus `/security-review` for tier 3.
     */
    private function reviewPass(Run $run, GitHub $github): AgentResult
    {
        $worktree = $this->worktree($run);
        $base = $worktree->baseRef();
        // A diff that fails assesses as no change, as before: the tier only picks which review passes run
        $diff = rescue(fn () => $worktree->diffSinceBase(context: 0), '', report: false);
        ['tier' => $tier, 'reason' => $reason] = RiskTier::assess(StackSet::detect($run->worktree_path), $diff);
        $level = [1 => 'low', 2 => 'medium', 3 => 'high'][$tier];

        $run->update(['risk_tier' => $tier]);
        $run->log("Risk tier {$tier}: {$reason}. Review pass: /code-review at {$level} effort, then /simplify.");

        $agent = $this->runPass($run, $github, 'The review pass', Prompt::render('review-pass', [
            'tier' => (string) $tier,
            'reason' => $reason,
            'level' => $level,
            'base' => $base,
        ]));

        if ($tier < 3) {
            return $agent;
        }

        // /security-review diffs against origin/HEAD, and quietly reviews nothing when that ref is missing
        $worktree->pointOriginHeadAtBase();

        $run->log('Security review: /security-review.');
        $agent = $this->runPass($run, $github, '/security-review', Prompt::render('security-review', [
            'reason' => $reason,
            'base' => $base,
        ]));

        if ($agent->cost() === 0.0) {
            throw new RuntimeException('/security-review ended without reviewing anything.');
        }

        return $agent;
    }

    /**
     * The scope judge's verdict on the diff since the base branch, or null when it gave no usable answer twice.
     * With `$reply`, the judge also gets the agent's answer to its veto.
     */
    private function judge(Run $run, string $issue, ?JudgeReply $reply = null): ?ScopeVerdict
    {
        $worktree = $this->worktree($run);
        $diff = $worktree->diffSinceBase();

        return (new ScopeJudge($run->repository, function (string $message) use ($run) {
            $run->log($message);
            $run->update(['heartbeat_at' => now()]);
            $this->guardCancel($run);
        }))->verdict($issue, $diff, $reply, $worktree);
    }

    /** The agent's answer to the judge, for the hand-off comment and the PR footer. */
    private static function judgeAnswer(JudgeReply $reply): string
    {
        return "The agent's answer to the judge:\n\n".self::quote($reply->claim);
    }

    /** Flag the issue for a human with `$comment`, and end the run as Needs you. */
    private function handToHuman(Run $run, GitHub $github, string $comment, string $log, string $stopReason): void
    {
        $github->flagForHuman($run->repository, $run->issue_number, $comment);
        $run->moveTo(RunStatus::NeedsHuman, ['finished_at' => now(), 'stop_reason' => $stopReason]);
        $run->log($log);
    }

    /** The parts as one markdown block quote, a blank quoted line between them. */
    private static function quote(string ...$parts): string
    {
        return collect(explode("\n", implode("\n\n", $parts)))->map(fn ($line) => rtrim("> {$line}"))->implode("\n");
    }

    /** Resume the agent's session with `$prompt`. Throws when it ends with an error. */
    private function runPass(Run $run, GitHub $github, string $label, string $prompt): AgentResult
    {
        $agent = $this->runAgent($run, $run->worktree_path, $github, $prompt);

        if ($agent->failed()) {
            throw new RuntimeException("{$label} stopped with an error (".($agent->subtype() ?? 'no result').').');
        }

        return $agent;
    }

    /**
     * Foreman's own gate, outside any hook: the integrity check, the verify script and the size limit.
     * Going over the size limit doesn't fail the gate; it makes the PR a draft.
     * Returns the footer for the PR body and whether the change is over the limit, or null after flagging the issue for a human with the output.
     *
     * @return array{footer:string,oversized:bool}|null
     */
    private function review(Run $run, GitHub $github): ?array
    {
        $repo = $run->repository;
        $base = $this->worktree($run)->baseRef();
        $verify = Gate::verifyScript($repo->verify_script, $run->worktree_path, $base);
        $limit = (int) config('foreman.max_pr_lines');

        $run->moveTo(RunStatus::Reviewing);
        $run->log('Reviewing: running the integrity check and the verify script before pushing.');

        $beat = 0;
        $failures = Gate::run(
            $run->worktree_path, $base, $repo->verify_script,
            before: fn () => $this->guardCancel($run),
            progress: function () use ($run, &$beat) {
                if (time() - $beat >= 30) {
                    $beat = time();
                    $run->update(['heartbeat_at' => now()]);
                }
            },
        );

        $failures = [...array_values($failures), ...$this->lockedChangeFailures($run)];

        if ($failures) {
            $this->handToHuman($run, $github,
                "Foreman's gate stopped this run before pushing. Nothing was pushed, and the branch is still in the worktree.\n\n".self::quote(...$failures),
                "Foreman's gate failed. Flagged the issue for a human.", 'gate_failed');

            return null;
        }

        $commands = collect(explode("\n", $verify))->map(fn ($c) => trim($c))->filter()->map(fn ($c) => "`{$c}`")->implode(', ');
        $lines = $this->worktree($run)->changedLines();
        $oversized = $lines > $limit;

        return ['oversized' => $oversized, 'footer' => "---\n**Foreman's gate**, run by Foreman outside the agent before the push:\n\n"
            .'- Verify script: '.($commands === '' ? 'none configured' : "passed ({$commands})")."\n"
            ."- Test integrity: passed\n"
            .($oversized ? "- Size: {$lines} changed lines, over the limit of {$limit}. Opened as a draft." : "- Size: {$lines} changed lines (limit {$limit})")];
    }

    /**
     * Locked paths and lock files the branch changed since it left the base branch.
     * The guard only sees edit tools, so this catches writes made from the shell.
     *
     * @return list<string>
     */
    private function lockedChangeFailures(Run $run): array
    {
        $repo = $run->repository;
        $files = $this->worktree($run)->changedFiles();

        $guard = new Guard(StackSet::detect($run->worktree_path), $run->worktree_path, $repo->lockedPathList(), $this->allowLockFiles);

        return array_map(fn ($reason) => "Locked file changed: {$reason}", $guard->lockedChanges($files));
    }

    /** Foreman's own push. A sandboxed agent commits unsigned, so its commits get signed first. */
    private function push(Run $run): void
    {
        $this->worktree($run)->push(sign: $run->repository->sandbox);
    }

    /** Post the agent's verdicts on the bot threads it was given, then watch the new head. */
    private function settleRound(Run $run, GitHub $github): void
    {
        $repo = $run->repository;

        if ($failures = $this->lockedChangeFailures($run)) {
            $this->handToHuman($run, $github,
                "Foreman's gate stopped this fix-up round before pushing. Nothing was pushed, and the branch is still in the worktree.\n\n".self::quote(...$failures),
                "Foreman's gate failed. Flagged the issue for a human.");

            return;
        }

        $this->push($run);
        $head = $github->pullRequest($repo, $run->pr_url)['head_sha'];
        $pushed = $head !== $run->head_sha;
        $verdicts = (new AgentReport($run->worktree_path))->verdicts();

        foreach ($run->pr_feedback['threads'] ?? [] as $thread) {
            $verdict = $verdicts[$thread['id']] ?? null;
            $reply = trim($verdict['reply'] ?? '');

            [$body, $resolve] = match (true) {
                $verdict === null => [null, false],
                $verdict['verdict'] === 'fixed' && $pushed => ['Fixed in '.substr($head, 0, 7).": {$reply}", true],
                $verdict['verdict'] === 'declined' => ["Not changing: {$reply}", true],
                $verdict['verdict'] === 'unsure' => ["Leaving for a human: {$reply}", false],
                default => [null, false],   // "fixed" with nothing pushed, or an unknown verdict
            };

            if ($body) {
                $github->replyToThread($repo, $thread['id'], $body);
            }

            if ($resolve) {
                $github->resolveThread($repo, $thread['id']);
            }

            $run->log("Thread {$thread['url']}: ".($resolve ? 'resolved' : 'left open').($body ? '' : ', no reply posted'));
        }

        $this->watch($run, $head);
        $run->log($pushed ? 'Pushed '.substr($head, 0, 7).'. Watching the PR again.' : 'Nothing new pushed. Watching the PR again.');
    }

    private function watch(Run $run, string $head, array $extra = []): void
    {
        $run->moveTo(RunStatus::WatchingPr, [
            'head_sha' => $head,
            'watch_started_at' => now(),
            'finished_at' => now(),
            ...$extra,
        ]);
    }

    private function roundPrompt(Run $run, GitHub $github): string
    {
        $notPassing = $run->pr_feedback['checks'] ?? [];

        $checks = collect($notPassing)
            ->map(fn ($c) => "- {$c['name']}: {$c['state']}".($c['url'] ? " ({$c['url']})" : ''))
            ->merge(collect($github->failedCheckLogs($run->repository, $notPassing))
                ->map(fn ($l) => "\nLog of the failed steps for ".implode(', ', $l['checks']).", the end of it:\n".IssueSanitiser::wrapText($l['log'], clean: false)))
            ->implode("\n") ?: 'None.';

        $threads = collect($run->pr_feedback['threads'] ?? [])
            ->map(fn ($t) => "### {$t['id']}\n{$t['author']} on {$t['path']}".($t['line'] ? ":{$t['line']}" : '')."\n\n".IssueSanitiser::wrapText($t['body']))
            ->implode("\n\n") ?: 'None.';

        return Prompt::render('pr-round', [
            'issue' => (string) $run->issue_number,
            'branch' => $run->branch,
            'pr' => $run->pr_url,
            'round' => (string) $run->pr_round,
            'max_rounds' => (string) config('foreman.pr_watch.max_rounds'),
            'review_file' => AgentReport::REVIEW_FILE,
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'checks' => $checks,
            'threads' => $threads,
        ]);
    }

    private function resumePrompt(Run $run, GitHub $github): string
    {
        $comments = $github->trustedComments($run->repository, $run->issue_number, $run->previousRun?->finished_at);

        $prompt = $comments
            ? "A human has replied on issue #{$run->issue_number}. The new comments are below.\n\n{$comments}"
            : "A human continued this run by hand. There are no new comments on issue #{$run->issue_number} from people with write access.";

        if ($this->mergedFromBase) {
            $commits = collect($this->mergedFromBase)->map(fn ($s) => "- {$s}")->implode("\n");
            $prompt .= "\n\nBefore this run, Foreman merged {$this->worktree($run)->baseRef()} into your branch. It brought in these commits:\n\n"
                .IssueSanitiser::wrapText($commits)
                ."\n\nThey may change what you found before, so run your checks again.";
        }

        return $prompt."\n\n".IssueSanitiser::TEXT_NOTE
            ."\n\nYour last ".AgentReport::RESULT_FILE.' was cleared. Write it again before you stop, even if your answer is the same.'
            ."\n\nContinue from where you stopped, following the same instructions as before.";
    }

    private function guardCancel(Run $run): void
    {
        if ($run->fresh()->cancel_requested) {
            throw new RuntimeException('Cancelled');
        }
    }
}
