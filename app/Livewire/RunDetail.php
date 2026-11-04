<?php

namespace App\Livewire;

use App\Enums\RunStatus;
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

    /** Save the operator's message on this run. It waits for Continue or Start over; nothing resumes here. */
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

        return view('livewire.run-detail', [
            'canContinue' => $this->run->canContinueAfterReply(),
            'takesMessages' => $this->run->takesMessages(),
            'chain' => $chain,
            'thread' => RunMessage::whereIn('run_id', $chain->pluck('id'))->orderBy('id')->get(),
            'log' => $this->run->logEntries(),
            'attempts' => $this->run->attempts(),
        ])->title("#{$this->run->issue_number} {$this->run->repository->name}");
    }
}
