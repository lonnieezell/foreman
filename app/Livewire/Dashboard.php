<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\Preflight;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Factory')]
class Dashboard extends Component
{
    public string $globalBudget = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->globalBudget = (string) (Setting::globalBudget() ?? '');
    }

    public function toggleRepository(int $id): void
    {
        $repo = Repository::findOrFail($id);
        $repo->update(['enabled' => ! $repo->enabled, 'last_polled_at' => null]);
        $this->flash = $repo->enabled ? "{$repo->name} started." : "{$repo->name} stopped. Runs in progress will finish.";
    }

    public function changeConcurrency(int $id, int $delta): void
    {
        $repo = Repository::findOrFail($id);
        $repo->update(['concurrency' => max(1, min(8, $repo->concurrency + $delta))]);
    }

    public function checkNow(int $id): void
    {
        Artisan::call('factory:poll', ['--repo' => $id, '--force' => true]);
        $repo = Repository::findOrFail($id);
        $this->flash = $repo->last_error ? null : "Checked {$repo->name} for ready issues.";
    }

    public function togglePause(): void
    {
        Setting::put('paused', Setting::paused() ? 0 : 1);
        Setting::put('pause_reason', null);
        $this->flash = Setting::paused() ? 'Factory paused. Runs in progress will finish.' : 'Factory resumed.';
    }

    public function resumeProfile(string $profile): void
    {
        Setting::resumeProfile($profile);
        $this->flash = "Resumed the {$profile} profile.";
    }

    public function saveGlobalBudget(): void
    {
        $this->validate(['globalBudget' => ['nullable', 'numeric', 'min:0']]);
        Setting::put('global_daily_budget', $this->globalBudget === '' ? null : $this->globalBudget);
        $this->flash = 'Daily budget saved.';
    }

    public function cancelRun(int $id): void
    {
        $run = Run::findOrFail($id);

        if ($run->status === RunStatus::Queued) {
            // Nobody has picked it up yet, so cancel it directly
            $run->moveTo(RunStatus::Cancelled, ['finished_at' => now()]);
            app(GitHub::class)->release($run->repository, $run->issue_number);
        } else {
            $run->update(['cancel_requested' => true]);
        }
    }

    public function retryRun(int $id): void
    {
        $run = Run::findOrFail($id);
        app(GitHub::class)->requeue($run->repository, $run->issue_number);
        $this->flash = "#{$run->issue_number} is back in the queue for {$run->repository->name}.";
    }

    public function recheck(): void
    {
        Preflight::forget();
    }

    public function render()
    {
        $repositories = Repository::query()
            ->with(['runs' => fn ($q) => $q->active()->oldest()])
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard', [
            'repositories' => $repositories,
            'recentRuns' => Run::with('repository')->latest()->limit(25)->get(),
            'paused' => Setting::paused(),
            'pauseReason' => Setting::get('pause_reason'),
            'pausedProfiles' => Setting::pausedProfiles(),
            'spentToday' => Setting::spentToday(),
            'budget' => Setting::globalBudget(),
            'problems' => app(Preflight::class)->problems(),
            'stations' => RunStatus::stations(),
        ]);
    }
}
