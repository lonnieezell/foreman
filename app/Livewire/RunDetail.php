<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\PermissionRequest;
use App\Models\Run;
use App\Models\RunMessage;
use App\Services\GitHub;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class RunDetail extends Component
{
    public Run $run;

    public ?string $flash = null;

    /** The operator's next message in the thread. */
    public string $message = '';

    /** @var array<int,string> The allowlist pattern for each waiting call, which the operator may edit before "Always allow". */
    public array $patterns = [];

    public function cancel(GitHub $github): void
    {
        $this->run->refresh();

        if ($this->run->status === RunStatus::Queued) {
            $this->run->moveTo(RunStatus::Cancelled, ['finished_at' => now()]);
            $github->release($this->run->repository, $this->run->issue_number);
        } elseif ($this->run->status === RunStatus::WatchingPr) {
            // No worker attached: stop watching and leave the PR as it is
            $this->run->moveTo(RunStatus::Cancelled);
            $this->run->log('Stopped watching the PR.');
            $this->run->removeWorktree();
        } elseif ($this->run->status->isActive()) {
            $this->run->update(['cancel_requested' => true]);
        }
    }

    /** Resume the same Claude session in the same worktree, after the human replied on the issue. */
    public function continueRun(GitHub $github)
    {
        $next = $this->run->continueAfterReply($github);

        if (! $next) {
            // A second click or tab, or a stale page: the button is gone once the run is continued
            $this->flash = 'This run can’t be continued. It may have been continued already.';

            return;
        }

        return $this->redirectRoute('runs.show', $next, navigate: true);
    }

    /** Merge the base branch and have the agent resolve the conflicts. */
    public function fixConflicts(GitHub $github)
    {
        $fix = $this->run->fixConflicts($github);

        if (is_string($fix)) {
            $this->flash = $fix;

            return;
        }

        return $this->redirectRoute('runs.show', $fix, navigate: true);
    }

    /** Save the operator's message on this run. A live agent gets it in its session; otherwise it waits for Continue or Start over. */
    public function postMessage(): void
    {
        $this->run->refresh();
        $this->message = trim($this->message);

        if (! $this->run->takesMessages()) {
            return;   // the page hides the box for this run
        }

        $this->validate(['message' => 'required']);

        $this->run->messages()->create(['role' => RunMessage::HUMAN, 'body' => $this->message]);
        $this->message = '';
    }

    /** Let the waiting call go ahead this once (ADR 0010). */
    public function allowOnce(int $id): void
    {
        $this->answer($id, PermissionRequest::ALLOW);
    }

    /** Let the waiting call go ahead, and add its pattern to the repository's allowlist so later calls like it don't ask. */
    public function alwaysAllow(int $id): void
    {
        $this->patterns[$id] = trim($this->patterns[$id] ?? '');
        // The allowlist is comma-separated, so a comma would split the pattern in two
        $this->validate(["patterns.{$id}" => ['required', 'not_regex:/,/']], [], ["patterns.{$id}" => 'pattern']);

        $this->answer($id, PermissionRequest::ALWAYS, $this->patterns[$id]);
    }

    public function deny(int $id): void
    {
        $this->answer($id, PermissionRequest::DENY);
    }

    private function answer(int $id, string $decision, ?string $pattern = null): void
    {
        // The live session sends the answer within a few seconds; the timeout may have settled it first
        if (! $this->run->permissionRequests()->findOrFail($id)->decide($decision, $pattern)) {
            $this->flash = 'That call was already answered, or it timed out.';
        }
    }

    public function retry(GitHub $github)
    {
        $github->requeue($this->run->repository, $this->run->issue_number);

        // Start it now if there's a free slot, instead of waiting for the repository's next poll
        Artisan::call('foreman:poll', ['--repo' => $this->run->repository_id, '--force' => true]);
        $next = $this->run->repository->activeRuns()->where('issue_number', $this->run->issue_number)->latest('id')->first();

        if ($next) {
            return $this->redirectRoute('runs.show', $next, navigate: true);
        }

        $this->flash = "#{$this->run->issue_number} is back in the queue. A new run will appear on the dashboard.";
    }

    public function render()
    {
        $this->run->refresh();
        $chain = $this->run->chain();
        // A request left from a session that ended can't be answered any more
        $permissions = $this->run->isLive() ? $this->run->pendingPermissions()->get() : collect();

        foreach ($permissions as $request) {
            $this->patterns[$request->id] ??= $request->suggestedPattern();
        }

        return view('livewire.run-detail', [
            'canContinue' => $this->run->canContinueAfterReply(),
            'canFixConflicts' => $this->run->canFixConflicts(),
            'takesMessages' => $this->run->takesMessages(),
            'permissions' => $permissions,
            'chain' => $chain,
            'thread' => RunMessage::whereIn('run_id', $chain->pluck('id'))->orderBy('id')->get(),
            'log' => $this->run->logEntries(),
            'attempts' => $this->run->attempts(),
        ])->title("#{$this->run->issue_number} {$this->run->repository->name}");
    }
}
