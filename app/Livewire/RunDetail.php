<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class RunDetail extends Component
{
    public Run $run;

    public ?string $flash = null;

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
        if (! $this->run->canContinueAfterReply()) {
            return;   // the page hides the button for this run, so a stale or forged call does nothing
        }

        return $this->redirectRoute('runs.show', $this->run->continueAfterReply($github), navigate: true);
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

        return view('livewire.run-detail', [
            'log' => $this->run->logEntries(),
            'attempts' => $this->run->attempts(),
        ])->title("#{$this->run->issue_number} {$this->run->repository->name}");
    }
}
