<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\Preflight;
use App\Services\Usage;
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

        if (! $repo->enabled) {
            $this->flash = "{$repo->name} stopped. Runs in progress will finish.";

            return;
        }

        // Check straight away instead of waiting for the scheduler
        $this->checkNow($id);
        $this->flash = $this->flash ? "{$repo->name} started and checked for ready issues." : null;
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
        $this->flash = null;   // the stats row already shows the new state
    }

    public function runAnyway(string $profile): void
    {
        app(Usage::class)->override($profile);
        $this->flash = "Next check will start runs on {$profile} regardless of usage.";
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

        // Start it now if there's a free slot, instead of waiting for the repository's next poll
        Artisan::call('factory:poll', ['--repo' => $run->repository_id, '--force' => true]);
        $started = $run->repository->activeRuns()->where('issue_number', $run->issue_number)->exists();

        $this->flash = "#{$run->issue_number} is back in the queue for {$run->repository->name}".($started ? ' and has started.' : '.');
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

        $usage = app(Usage::class);
        $profiles = $repositories->map->profileName()->unique()->sort()->values();
        $tokens = Run::tokensTodayByProfile();

        return view('livewire.dashboard', [
            'repositories' => $repositories,
            'recentRuns' => Run::with('repository')->latest()->limit(25)->get(),
            'paused' => Setting::paused(),
            'pauseReason' => Setting::get('pause_reason'),
            // Every configured profile gets a gauge, as do any a repository uses or that spent tokens today
            'gauges' => collect(array_keys(config('factory.claude_profiles')) ?: ['default'])
                ->merge($profiles)->merge(array_keys($tokens))->unique()->sort()
                ->mapWithKeys(fn ($p) => [$p => $usage->gauge($p)])->all(),
            'heldProfiles' => $profiles->mapWithKeys(fn ($p) => [$p => $usage->holdReason($p)])->filter()->all(),
            'overrides' => $profiles->filter(fn ($p) => $usage->overridePending($p))->values()->all(),
            'spentToday' => Setting::spentToday(),
            'tokensByProfile' => $tokens,
            'budget' => Setting::globalBudget(),
            'problems' => app(Preflight::class)->problems(),
            'stations' => RunStatus::stations(),
        ]);
    }
}
