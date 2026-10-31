<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use App\Services\GitHub;
use App\Services\Preflight;
use App\Services\Usage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Foreman')]
class Dashboard extends Component
{
    public ?string $flash = null;

    // Opens the add repository modal; in the URL so the masthead link can open it from any page
    #[Url(as: 'add', except: false)]
    public bool $adding = false;

    // The repository whose settings are open in the same modal
    #[Url(as: 'edit', except: null)]
    public ?int $editing = null;

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

    public function closeModal(): void
    {
        $this->adding = false;
        $this->editing = null;
    }

    public function changeConcurrency(int $id, int $delta): void
    {
        $repo = Repository::findOrFail($id);
        $repo->update(['concurrency' => max(1, min(8, $repo->concurrency + $delta))]);
    }

    public function checkNow(int $id): void
    {
        Artisan::call('foreman:poll', ['--repo' => $id, '--force' => true]);
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

    public function retryRun(int $id): void
    {
        $run = Run::findOrFail($id);
        app(GitHub::class)->requeue($run->repository, $run->issue_number);

        // Start it now if there's a free slot, instead of waiting for the repository's next poll
        Artisan::call('foreman:poll', ['--repo' => $run->repository_id, '--force' => true]);
        $started = $run->repository->activeRuns()->where('issue_number', $run->issue_number)->exists();

        $this->flash = "#{$run->issue_number} is back in the queue for {$run->repository->name}".($started ? ' and has started.' : '.');
    }

    public function continueRun(int $id)
    {
        return $this->redirectRoute('runs.show', Run::findOrFail($id)->continueAfterReply(app(GitHub::class)), navigate: true);
    }

    public function recheck(): void
    {
        Preflight::forget();
    }

    /**
     * Issues grouped by their latest run's status, attention sections first.
     *
     * @return list<array{title:string,statuses:list<RunStatus>,runs:Collection,attention:bool}>
     */
    private function sections(): array
    {
        $latest = fn () => Run::latestPerIssue()
            ->whereHas('repository', fn ($q) => $q->whereNull('archived_at'))
            ->with('repository')
            ->latest('id');

        $runs = $latest()->whereIn('status', [RunStatus::NeedsHuman, RunStatus::Failed, RunStatus::PrReady, RunStatus::PrOpened, RunStatus::WatchingPr])->get();
        $handled = $latest()->where('status', RunStatus::Cancelled)->limit(10)->get();

        $all = $runs->merge($handled);

        return array_map(fn (array $section) => [...$section, 'runs' => $all->whereIn('status', $section['statuses'])->values()], [
            ['title' => 'Needs your reply', 'statuses' => [RunStatus::NeedsHuman], 'attention' => true],
            ['title' => 'Failed', 'statuses' => [RunStatus::Failed], 'attention' => true],
            ['title' => 'Ready for review', 'statuses' => [RunStatus::PrReady, RunStatus::PrOpened], 'attention' => true],
            ['title' => 'Watching PRs', 'statuses' => [RunStatus::WatchingPr], 'attention' => false],
            ['title' => 'Handled', 'statuses' => [RunStatus::Cancelled], 'attention' => false],
        ]);
    }

    public function render()
    {
        $repositories = Repository::query()
            ->whereNull('archived_at')
            ->with(['runs' => fn ($q) => $q->active()->oldest()])
            ->orderBy('name')
            ->get();

        $usage = app(Usage::class);
        $profiles = $repositories->map->profileName()->unique()->sort()->values();
        $tokens = Run::tokensTodayByProfile();

        return view('livewire.dashboard', [
            'repositories' => $repositories,
            'editingRepository' => $this->editing ? $repositories->find($this->editing) : null,
            'sections' => $this->sections(),
            'doneToday' => Run::doneToday()->toBase()->selectRaw('repository_id, status, count(*) as total')->groupBy('repository_id', 'status')->get()
                ->groupBy('repository_id')->map->pluck('total', 'status'),
            'paused' => Setting::paused(),
            'pauseReason' => Setting::get('pause_reason'),
            // Every configured profile gets a gauge, as do any a repository uses or that spent tokens today
            'gauges' => collect(array_keys(config('foreman.claude_profiles')) ?: ['default'])
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
