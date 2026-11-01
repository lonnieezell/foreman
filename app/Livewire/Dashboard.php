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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    // The Handled section starts collapsed
    public bool $showHandled = false;

    // Ids of the attention runs this tab has already seen; null until the first render
    public ?array $seenAttention = null;

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

    /** Returns null once saved, or the first validation message. Applies to new runs only. */
    public function saveClaudeSettings(int $id, string $model, string $effort): ?string
    {
        $repo = Repository::findOrFail($id);
        $validator = Validator::make(['model' => $model, 'effort' => $effort], [
            'model' => ['required', Rule::in(array_keys(Repository::MODELS))],
            'effort' => ['nullable', Rule::in(array_keys(Repository::EFFORTS))],
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $repo->update(['model' => $model, 'effort' => $effort ?: null]);

        return null;
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

    public function continueRun(int $id): void
    {
        $run = Run::findOrFail($id);

        if (! $run->canContinueAfterReply()) {
            return;   // the card hides the button for this run, so a stale or forged call does nothing
        }

        $run->continueAfterReply(app(GitHub::class));

        $this->flash = "#{$run->issue_number} is continuing after your reply in {$run->repository->name}.";
    }

    public function recheck(): void
    {
        Preflight::forget();
    }

    /** Mark an issue handled outside Foreman. Only its latest run can be dismissed; nothing is sent to GitHub. */
    public function dismissRun(int $id): void
    {
        $run = Run::latestPerIssue()->find($id);

        if ($run && in_array($run->status, [RunStatus::NeedsHuman, RunStatus::Failed, RunStatus::PrReady, RunStatus::PrOpened], true)) {
            $run->update(['handled_at' => now()]);
        }
    }

    public function restoreRun(int $id): void
    {
        Run::latestPerIssue()->whereKey($id)->update(['handled_at' => null]);
    }

    /**
     * Issues grouped by their latest run's status, attention sections first. Dismissed issues
     * move to Handled, next to cancelled ones.
     *
     * @return list<array{title:string,statuses:list<RunStatus>,runs:Collection,attention:bool}>
     */
    private function sections(): array
    {
        $latest = fn () => Run::latestPerIssue()
            ->whereHas('repository', fn ($q) => $q->whereNull('archived_at'))
            ->with('repository');

        $runs = $latest()->latest('id')->whereNull('handled_at')
            ->whereIn('status', [RunStatus::NeedsHuman, RunStatus::Failed, RunStatus::PrReady, RunStatus::PrOpened, ...RunStatus::stations(), RunStatus::WatchingPr])->get();
        $handled = $latest()
            ->where(fn ($q) => $q->whereNotNull('handled_at')->orWhere('status', RunStatus::Cancelled))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(10)->get();

        $sections = array_map(fn (array $section) => [...$section, 'runs' => $runs->whereIn('status', $section['statuses'])->values()], [
            ['title' => 'Needs your reply', 'statuses' => [RunStatus::NeedsHuman], 'attention' => true],
            ['title' => 'Failed', 'statuses' => [RunStatus::Failed], 'attention' => true],
            ['title' => 'Ready for review', 'statuses' => [RunStatus::PrReady, RunStatus::PrOpened], 'attention' => true],
            ['title' => 'In progress', 'statuses' => RunStatus::stations(), 'attention' => false],
            ['title' => 'Watching PRs', 'statuses' => [RunStatus::WatchingPr], 'attention' => false],
        ]);
        $sections[] = ['title' => 'Handled', 'statuses' => [], 'runs' => $handled, 'attention' => false];

        return $sections;
    }

    /**
     * Tells the browser about runs that reached an attention section since the last render. Each
     * run id is announced once, so a retry that fails again (a new run) announces again. The first
     * render only records what is already waiting. Dismissed and archived issues are recorded but
     * never announced, so restoring them later doesn't announce either.
     */
    private function announceNewAttention(): void
    {
        $runs = Run::latestPerIssue()
            ->whereIn('status', [RunStatus::NeedsHuman, RunStatus::Failed, RunStatus::PrReady, RunStatus::PrOpened])
            ->with('repository')
            ->get();

        $new = $this->seenAttention === null ? collect() : $runs->whereNotIn('id', $this->seenAttention);
        $this->seenAttention = $runs->pluck('id')->all();

        $notifications = $new
            ->filter(fn (Run $run) => ! $run->handled_at && ! $run->repository->archived_at)
            ->map(fn (Run $run) => [
                'id' => $run->id,
                'title' => "#{$run->issue_number} {$run->repository->name} ".match ($run->status) {
                    RunStatus::NeedsHuman => 'needs your reply',
                    RunStatus::Failed => 'failed',
                    default => 'is ready for review',
                },
                'body' => $run->error ?: (string) $run->issue_title,
                'url' => route('runs.show', $run),
            ])
            ->values()->all();

        if ($notifications && Setting::desktopNotifications()) {
            $this->dispatch('foreman-notify', notifications: $notifications);
        }
    }

    /**
     * Issues waiting on the operator: the cards across the attention sections, with a caption
     * that breaks the count down by section and leaves out the empty ones.
     *
     * @param  list<array{title:string,runs:Collection,attention:bool}>  $sections
     * @return array{count:int,caption:string}
     */
    private function attention(array $sections): array
    {
        $parts = [
            'Needs your reply' => 'need reply',
            'Failed' => 'failed',
            'Ready for review' => 'to review',
        ];
        $counts = collect($sections)->where('attention', true)->mapWithKeys(fn ($s) => [$s['title'] => $s['runs']->count()]);

        return [
            'count' => $counts->sum(),
            'caption' => collect($parts)->map(fn ($label, $title) => $counts[$title] ? "{$counts[$title]} {$label}" : null)->filter()->implode(' · '),
        ];
    }

    public function render()
    {
        $this->announceNewAttention();

        $repositories = Repository::query()
            ->whereNull('archived_at')
            ->with(['runs' => fn ($q) => $q->active()->oldest()])
            ->orderBy('name')
            ->get();

        $sections = $this->sections();
        $attention = $this->attention($sections);

        // Runs on every 3s poll, so a background tab's title follows the count
        $this->js('document.title = '.json_encode($attention['count'] ? "({$attention['count']}) Foreman" : 'Foreman'));

        $usage = app(Usage::class);
        $profiles = $repositories->map->profileName()->unique()->sort()->values();
        $tokens = Run::tokensTodayByProfile();

        return view('livewire.dashboard', [
            'repositories' => $repositories,
            'groups' => $repositories->groupBy->profileName()->sortKeys(),
            'editingRepository' => $this->editing ? $repositories->find($this->editing) : null,
            'sections' => $sections,
            'attention' => $attention,
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
