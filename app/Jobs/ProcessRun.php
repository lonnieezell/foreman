<?php

namespace App\Jobs;

use App\Enums\Difficulty;
use App\Enums\Outcome;
use App\Enums\RunStatus;
use App\Guardrails\Guard;
use App\Guardrails\RiskTier;
use App\Guardrails\StackSet;
use App\Models\Run;
use App\Models\RunMessage;
use App\Services\AgentOutcome;
use App\Services\AgentReport;
use App\Services\Claude\AgentResult;
use App\Services\Claude\Claude;
use App\Services\Claude\SessionNotFound;
use App\Services\Gate;
use App\Services\GitHub;
use App\Services\JudgeReply;
use App\Services\ScopeJudge;
use App\Services\ScopeVerdict;
use App\Services\StepPicker;
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

    /** @var list<string> The files a conflict fix's merge left conflicted. */
    private array $conflicts = [];

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
            $labels = $this->pickStep($run, $github);

            if ($run->isConflictFix()) {
                $this->fixConflicts($run, $github, $labels);

                return;
            }

            if ($run->resume_session_id && $run->worktree_path && File::isDirectory($run->worktree_path)) {
                $worktree = $run->worktree_path;   // continuing: keep the agent's work in progress
                $this->catchUpWithBase($run, fetch: true);
            } else {
                $worktree = $this->prepareWorktree($run);
            }

            // Clear the last run's result and verdicts, and keep them out of git
            (new AgentReport($worktree))->clear();
            $this->worktree($run)->excludeFromGit('.foreman/');

            $this->allowLockFiles = in_array('dependencies', $labels, true);
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

    /**
     * In Auto mode, set the run's model and effort from a label, an earlier run's pick, the classifier or the fallback,
     * clamped to the repository's range. In Fixed mode the run keeps the repository's, and labels do nothing (ADR 0004).
     *
     * @return list<string> The issue's labels.
     */
    private function pickStep(Run $run, GitHub $github): array
    {
        $repo = $run->repository;
        $labels = $github->labels($repo, $run->issue_number);

        if (! $repo->isAuto()) {
            if ($label = Difficulty::fromLabels($labels)) {
                $run->log("Ignoring the {$label->label()} label: this repository is in Fixed mode.");
            }

            return $labels;
        }

        $picker = new StepPicker($repo, $github, function (string $message) use ($run) {
            $run->log($message);
            $run->update(['heartbeat_at' => now()]);
            $this->guardCancel($run);
        });
        $pick = $picker->pick($run->issue_number, $labels, $run->earlierPick());

        $run->update($pick->attributes());
        array_map($run->addUsage(...), $picker->usage);
        $run->log($pick->describe());

        return $labels;
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

        $this->step($run, 'Running setup script', ['bash', '-ec', $repo->setup_script], $worktree, timeout: 900, env: ForemanEnv::forWorktree($worktree));

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
     * @param  string|null  $followUp  A later pass's prompt. Its usage adds to the run's.
     * @param  bool  $fresh  The follow-up starts a session of its own instead of resuming the run's, and leaves the run's session id alone.
     */
    private function runAgent(Run $run, string $worktree, GitHub $github, ?string $followUp = null, bool $fresh = false): AgentResult
    {
        $repo = $run->repository;
        $budget = $repo->max_budget_per_run;

        if ($followUp === null) {
            $run->moveTo(RunStatus::Working, ['claude_profile' => $repo->profileName()]);
            $effort = $run->effort ? "{$run->effort} effort, " : '';
            $run->log("Starting Claude ({$run->model}, {$effort}{$repo->profileName()} profile, max {$repo->max_turns} turns, {$repo->max_minutes} min)");
        }

        // The classifier's call can spend before the first pass
        if ($budget !== null && ($followUp !== null || (float) $run->cost_usd > 0)) {
            // The spend cap is per run, so a pass gets what the run has left
            $budget = round((float) $budget - (float) $run->cost_usd, 2);

            if ($budget <= 0) {
                throw new RuntimeException("The run used up its spend cap of \${$repo->max_budget_per_run} before Foreman could resume the agent.");
            }
        }

        $prompt = match (true) {
            $followUp !== null => $followUp,
            $run->isConflictFix() => $this->conflictPrompt($run, $github),
            (bool) $run->pr_round => $this->roundPrompt($run, $github),
            (bool) $run->resume_session_id => $this->resumePrompt($run, $github),
            default => $repo->renderPrompt($run, $github->sanitisedIssue($repo, $run->issue_number)).$this->decisions($run),
        };

        // A follow-up resumes the same conversation, so the agent doesn't re-explore the codebase
        $resume = match (true) {
            $fresh => null,
            $followUp !== null => $run->session_id,
            default => $run->resume_session_id,
        };

        // A fresh session is never saved: a correction round or a continued run must resume the implementation's
        $agent = app(Claude::class)->agent($run, $worktree, $prompt, $resume, $budget, $this->allowLockFiles, saveSession: ! $fresh);
        // Adds to what the run already spent, such as the classifier's call or an earlier pass
        $run->addUsage($agent->usage());

        if (! $fresh) {
            $run->update(['session_id' => $agent->sessionId ?? $run->session_id]);
        }

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
                // Added and missing work are easy to spot in review, so they open a draft if the gate passes
                if (! $verdict->weakensTests) {
                    break;
                }

                $answer = $result->judgeReply === null
                    ? "The agent's PR body:\n\n".self::quote((string) str($result->body)->limit(2000))
                    : self::judgeAnswer($result->judgeReply);

                $this->handToHuman($run, $github,
                    "Foreman's scope judge vetoed this change twice, once before and once after a correction round. Nothing was pushed, and the branch is still in the worktree.\n\n".self::quote($verdict->describe())."\n\n{$answer}",
                    "Scope judge vetoed the change again: {$verdict->describe()} Flagged the issue for a human.", 'scope_vetoed');

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
            $run->log("Scope judge vetoed the change again: {$verdict->describe()} Opening the PR as a draft.");
            $footer .= "\n- Scope judge: vetoed twice, so opened as a draft. {$verdict->describe()}";
            $footer .= $result->judgeReply === null ? '' : "\n\n".self::judgeAnswer($result->judgeReply);

            if ($verdict->addsUnrequested) {
                $labels[] = 'scope:extra';
            }

            if ($verdict->missesRequested) {
                $labels[] = 'scope:incomplete';
            }
        } else {
            $passed = $corrected ? 'passed after one correction round' : 'passed';
            $run->log("Scope judge {$passed}: {$verdict->reason}");
            $footer .= "\n- Scope judge: {$passed}. {$verdict->reason}";
        }

        if ($run->difficulty_source) {
            $footer .= "\n- Difficulty: ".($run->difficulty ?? 'none')." ({$run->difficulty_source}) → {$run->model} {$run->effort}";
        }

        $this->push($run);

        $body = str_starts_with($result->body, "Closes #{$run->issue_number}") ? $result->body : "Closes #{$run->issue_number}\n\n{$result->body}";
        $body .= "\n\n{$footer}";
        $prUrl = $github->openPullRequest($repo, $run->branch, $result->title, $body, draft: $labels !== []);

        // Never on the issue: an issue label is an override (ADR 0004)
        $difficulty = $run->difficulty ? ["difficulty:{$run->difficulty}"] : [];

        foreach (["risk:tier-{$run->risk_tier}", ...$difficulty, ...$labels] as $label) {
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
            throw self::noResult($agent);
        }

        if ($result->kind === Outcome::Question) {
            // The questions stay in Foreman; the issue only learns that one was asked
            $run->messages()->createMany(array_map(fn (string $q) => ['role' => RunMessage::AGENT, 'body' => $q], $result->questions));
            $count = count($result->questions);
            $this->handToHuman($run, $github, "Foreman's agent has a question. It's being answered in Foreman.",
                'Agent asked '.$count.' '.str('question')->plural($count).'.', 'question');

            return null;
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

    /** Why there is no outcome to act on, for a pass that left no readable result file. */
    private static function noResult(AgentResult $agent): RuntimeException
    {
        $reason = $agent->isError()
            ? 'Claude stopped with an error ('.($agent->subtype() ?? 'unknown').').'
            : 'The agent finished without a readable result file ('.AgentReport::RESULT_FILE.').';

        // Its last message usually says why, so keep it where a human will see it
        $last = $agent->message();

        return new RuntimeException($last === '' ? $reason : "{$reason} Its last message:\n\n".str($last)->limit(2000));
    }

    /**
     * Merge the base branch into the PR's branch, have the agent resolve the conflicts, and push without force.
     * No gate, review pass or scope judge, as in a fix-up round: CI on the PR is the check.
     */
    /** @param  list<string>  $labels  The issue's labels. */
    private function fixConflicts(Run $run, GitHub $github, array $labels): void
    {
        try {
            $this->mergeAndResolve($run, $github, $labels);
        } catch (Throwable $e) {
            // Cancelling goes the usual way
            if ($run->fresh()->cancel_requested) {
                throw $e;
            }

            // The PR is the operator's: no comment on GitHub, and the run stays Needs you
            $this->conflictFixFailed($run, $github, 'Foreman: '.$e->getMessage(), 'Failed: '.$e->getMessage());
        }
    }

    private function mergeAndResolve(Run $run, GitHub $github, array $labels): void
    {
        $repo = $run->repository;
        $path = $repo->worktreePath($run->issue_number);

        // Always rebuilt: an old folder may hold anything, and the resume path's catch-up aborts on a conflict
        $pushed = $this->worktree($run, $path)->prepare(continuing: true);
        $run->update(['worktree_path' => $path]);

        if (! $pushed) {
            throw new RuntimeException("{$run->branch} isn’t on origin, so there is no PR branch to fix.");
        }

        $git = $this->worktree($run);

        if (($head = $git->head()) !== $run->head_sha) {
            $this->branchMoved($run, $github, $head);

            return;
        }

        // On the clean PR head: an install step would fail on a lock file full of conflict markers
        $this->step($run, 'Running setup script', ['bash', '-ec', $repo->setup_script], $path, timeout: 900, env: ForemanEnv::forWorktree($path));

        (new AgentReport($path))->clear();
        $git->excludeFromGit('.foreman/');
        $this->allowLockFiles = in_array('dependencies', $labels, true);

        $run->log("Merging {$git->baseRef()} into {$run->branch}");
        $merge = $git->mergeInBase();

        if ($merge['conflicts'] === []) {
            $run->log("{$git->baseRef()} merged cleanly, so the agent isn't needed.");
        } elseif (! $this->resolveConflicts($run, $github, $merge['conflicts'])) {
            return;
        }

        if ($failures = $this->conflictFixPushFailures($run, $merge)) {
            $this->conflictFixFailed($run, $github,
                "Foreman: nothing was pushed, because the branch failed Foreman's checks before the push.\n\n".implode("\n", array_map(fn ($f) => "- {$f}", $failures)),
                "Foreman's checks before the push failed: ".implode(' ', $failures));

            return;
        }

        try {
            $this->push($run);
        } catch (RuntimeException $e) {
            // Without force a push is refused when the branch moved; any other refusal is a failed fix
            $remote = $github->pullRequest($repo, $run->pr_url)['head_sha'];

            if ($remote !== $run->head_sha) {
                $this->branchMoved($run, $github, $remote);

                return;
            }

            throw $e;
        }

        // A push without force that went through leaves GitHub at this commit, even before its API catches up
        $head = $git->head();
        $this->watch($run, $head);
        $run->log('Pushed '.substr($head, 0, 7).'. Watching the PR again.');
    }

    /** Run the agent on the conflicts. Gives false after ending the run, when the agent gave up. */
    private function resolveConflicts(Run $run, GitHub $github, array $conflicts): bool
    {
        $this->conflicts = $conflicts;
        $run->log('Conflicts in: '.implode(', ', $conflicts));

        try {
            $agent = $this->runAgent($run, $run->worktree_path, $github);
        } catch (SessionNotFound) {
            // Without a session the prompt carries the issue and both sides instead
            $run->log("Claude couldn't find the agent's session. Starting the agent fresh, once.");
            $run->update(['resume_session_id' => null]);
            $agent = $this->runAgent($run, $run->worktree_path, $github);
        }

        $result = (new AgentReport($run->worktree_path))->outcome() ?? throw self::noResult($agent);

        if ($result->kind === Outcome::Pr) {
            return true;
        }

        $this->conflictFixFailed($run, $github,
            $result->kind === Outcome::Question ? implode("\n\n", $result->questions) : $result->comment,
            "The agent couldn't resolve the conflicts.");

        return false;
    }

    /**
     * What stops a conflict fix's push: the merge not in the branch, markers left in the conflicted files, or a locked file changed.
     *
     * @param  array{base:string,conflicts:list<string>}  $merge
     * @return list<string>
     */
    private function conflictFixPushFailures(Run $run, array $merge): array
    {
        $git = $this->worktree($run);

        return [
            ...($git->contains($merge['base']) ? [] : ["{$git->baseRef()} (".substr($merge['base'], 0, 7).") isn't in the branch, so the merge wasn't committed."]),
            ...array_map(fn ($file) => "Conflict markers are still in {$file}.", $merge['conflicts'] ? $git->markersAt($merge['conflicts']) : []),
            ...$this->lockedChangeFailures($run),
        ];
    }

    /** Someone else's commit is on the branch, so Foreman leaves the PR alone, as the watcher does. The click took the human label off, so it goes back. */
    private function branchMoved(Run $run, GitHub $github, string $head): void
    {
        $github->addHumanLabel($run->repository, $run->issue_number);
        $run->moveTo(RunStatus::NeedsHuman, ['finished_at' => now(), 'stop_reason' => 'someone_pushed']);
        $run->log('Someone else pushed '.substr($head, 0, 7).' to the branch. Leaving the PR to you.');
    }

    /** End a conflict fix as Needs you with `$explanation` in its thread. Nothing goes on GitHub but the human label. */
    private function conflictFixFailed(Run $run, GitHub $github, string $explanation, string $log): void
    {
        $run->messages()->create(['role' => RunMessage::AGENT, 'body' => $explanation]);
        $github->addHumanLabel($run->repository, $run->issue_number);
        $run->moveTo(RunStatus::NeedsHuman, ['finished_at' => now(), 'stop_reason' => 'conflict_fix_failed']);
        $run->log($log);
    }

    private function conflictPrompt(Run $run, GitHub $github): string
    {
        $git = $this->worktree($run);
        $base = $git->baseRef();
        $context = '';

        if (! $run->resume_session_id) {
            $sides = $git->conflictSides($this->conflicts);
            $diff = fn (string $side) => IssueSanitiser::wrapText((string) str($side)->limit(20000), clean: false);

            $context = "Foreman couldn't resume your earlier session, so you start fresh. This is the issue the PR is for:\n\n"
                .$github->sanitisedIssue($run->repository, $run->issue_number)
                ."\n\nWhat your branch changed in those files since it split from {$base}:\n\n".$diff($sides['ours'])
                ."\n\nWhat {$base} changed in them since then:\n\n".$diff($sides['theirs']);
        }

        return Prompt::render('conflict-fix', [
            'pr' => $run->pr_url,
            'issue' => (string) $run->issue_number,
            'base' => $base,
            'branch' => $run->branch,
            'files' => collect($this->conflicts)->map(fn ($f) => "- `{$f}`")->implode("\n"),
            'context' => $context,
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'result_file' => AgentReport::RESULT_FILE,
        ]);
    }

    /**
     * Rate the change's risk from its diff, then review it in fresh sessions as deep as the tier calls for:
     * `/code-review` and `/simplify`, plus `/security-review` for tier 3. A fresh reviewer isn't anchored on the author's
     * reasoning, and doesn't re-read the implementation's whole context on every turn.
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

        if ($run->repository->prompt_template) {
            $run->log("The review passes don't use this repository's custom prompt. They rely on its CLAUDE.md or AGENTS.md.");
        }

        // A fresh session knows nothing of the run, so it gets the issue and the gate's checks
        $verify = Gate::verifyScript($run->repository->verify_script, $run->worktree_path, $base);
        $context = [
            'base' => $base,
            'reason' => $reason,
            'issue_text' => $github->sanitisedIssue($run->repository, $run->issue_number),
            'decisions' => $this->decisions($run),
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'checks' => $verify === ''
                ? 'Foreman has no verify script for this repository. Run the checks that AGENTS.md or CLAUDE.md lists, for every part of the stack the change touches.'
                : "These are the checks Foreman's gate runs:\n\n```bash\n{$verify}\n```",
        ];

        $agent = $this->runPass($run, $github, 'The review pass', Prompt::render('review-pass', [
            ...$context,
            'tier' => (string) $tier,
            'level' => $level,
        ]), fresh: true);

        if ($tier < 3) {
            return $agent;
        }

        // /security-review diffs against origin/HEAD, and quietly reviews nothing when that ref is missing
        $worktree->pointOriginHeadAtBase();

        $run->log('Security review: /security-review.');
        $agent = $this->runPass($run, $github, '/security-review', Prompt::render('security-review', $context), fresh: true);

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

    /** Resume the agent's session with `$prompt`, or start a `$fresh` one. Throws when it ends with an error. */
    private function runPass(Run $run, GitHub $github, string $label, string $prompt, bool $fresh = false): AgentResult
    {
        $file = "{$run->worktree_path}/".AgentReport::RESULT_FILE;
        $result = $fresh ? @file_get_contents($file) : null;

        $agent = $this->runAgent($run, $run->worktree_path, $github, $prompt, $fresh);

        if ($fresh) {
            $run->log("{$label} ran in a fresh session (".($agent->sessionId ?? 'no session id').').');
        }

        if ($agent->failed()) {
            throw new RuntimeException("{$label} stopped with an error (".($agent->subtype() ?? 'no result').').');
        }

        // A reviewer may ask or hand over, but the PR's title and body are the author's
        if ($fresh && (new AgentReport($run->worktree_path))->outcome()?->kind === Outcome::Pr && @file_get_contents($file) !== $result) {
            File::put($file, $result);
            $run->log("{$label} changed ".AgentReport::RESULT_FILE.'. Foreman put it back.');
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
        $messages = $run->previousRun?->messages ?? collect();
        // Written in Foreman, which runs on localhost only, so it is trusted operator text (ADR 0001)
        $answers = $messages->where('role', RunMessage::HUMAN)->pluck('body')->implode("\n\n");
        // A session that read the issue wrote these, so they are not
        $questions = $messages->where('role', RunMessage::AGENT)->map(fn (RunMessage $m) => IssueSanitiser::wrapText($m->body, clean: false))->implode("\n\n");

        $replies = array_filter([
            $answers === '' ? null : "Your operator answered in Foreman. Their messages are below.\n\n{$answers}",
            $comments === '' ? null : "A human has replied on issue #{$run->issue_number}. The new comments are below.\n\n{$comments}",
        ]);

        $prompt = $replies
            ? implode("\n\n", $replies).(count($replies) === 2 ? "\n\nIf the issue comments conflict with your operator's answer, the operator's answer wins." : '')
            : "A human continued this run by hand. There are no new comments on issue #{$run->issue_number} from people with write access.";

        // Only alongside a reply: with none, the run was continued by hand
        if ($replies && $questions !== '') {
            $prompt = "These questions were asked in Foreman during the last run. You asked them, or Foreman's review pass did. "
                ."The review pass runs in a separate session and may have committed fixes. `git log` shows them.\n\n{$questions}\n\n{$prompt}";
        }

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

    /**
     * Every earlier question and answer for the issue, so a fresh run keeps decisions that exist only in Foreman (ADR 0001).
     * Empty when there are none.
     */
    private function decisions(Run $run): string
    {
        $thread = RunMessage::query()
            // A failed conflict fix's thread explains a failure; it asks nothing
            ->whereHas('run', fn ($q) => $q->where('repository_id', $run->repository_id)->where('issue_number', $run->issue_number)->whereNull('kind'))
            ->where('run_id', '!=', $run->id)
            ->orderBy('id')
            ->get()
            // The agent's questions come from a session that read the issue, so only the operator's answers are trusted
            ->map(fn (RunMessage $m) => $m->role === RunMessage::AGENT
                ? "Agent asked:\n".IssueSanitiser::wrapText($m->body, clean: false)
                : "Operator answered:\n{$m->body}")
            ->implode("\n\n");

        return $thread === '' ? '' : "\n\n## Decisions your operator already made\n\n"
            ."Earlier runs on this issue asked your operator these questions in Foreman. The answers are trusted operator text, newest last. Treat them as settled.\n\n"
            .IssueSanitiser::TEXT_NOTE."\n\n"
            .$thread;
    }

    private function guardCancel(Run $run): void
    {
        if ($run->fresh()->cancel_requested) {
            throw new RuntimeException('Cancelled');
        }
    }
}
