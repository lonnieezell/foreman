<?php

namespace App\Services\Claude;

use App\Models\Repository;
use App\Models\Run;

/**
 * Every call Foreman makes to the `claude` CLI. `ClaudeCli` is the real thing; tests swap in a fake.
 */
interface Claude
{
    /**
     * One pass of the agent in the run's worktree: it works, Foreman watches, and the outcome comes back.
     * Throws when the run is cancelled, runs past its time limit, or Claude exits without a result.
     *
     * @param  string|null  $resume  A session to continue, so the agent doesn't re-explore the codebase.
     * @param  string|float|null  $budget  The most this pass may spend, in USD. Null means no cap.
     * @param  bool  $allowLockFiles  Lets the agent edit lock files, for issues labelled `dependencies`.
     */
    public function agent(Run $run, string $worktree, string $prompt, ?string $resume = null, string|float|null $budget = null, bool $allowLockFiles = false): AgentResult;

    /**
     * A single Haiku call with no tools, outside any project, for the scope judge and the usage probe.
     *
     * @param  array|null  $schema  A JSON schema the answer must follow. It comes back as `structured`.
     */
    public function oneShot(Repository $repo, string $prompt, ?array $schema = null): OneShotResult;
}
