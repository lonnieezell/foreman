<div wire:poll.3s>
    @if ($flash)
        <div class="notice" wire:key="flash-{{ md5($flash) }}">
            <p>{{ $flash }}</p>
        </div>
    @endif

    @if ($problems)
        <div class="notice notice-halt">
            @foreach ($problems as $problem)
                <p><strong>{{ $problem['problem'] }}</strong> {!! Str::inlineMarkdown(e($problem['fix'])) !!}</p>
            @endforeach
            <p><button class="btn btn-quiet" style="padding-left:0" wire:click="recheck">Check again</button></p>
        </div>
    @endif

    @foreach ($heldProfiles as $profile => $reason)
        <div class="notice" wire:key="profile-hold-{{ $profile }}">
            <p>
                <strong>Holding new runs on the {{ $profile }} profile.</strong> {{ $reason }}
                @if (in_array($profile, $overrides, true))
                    Next check will start runs on {{ $profile }} regardless of usage.
                @else
                    <button class="btn" wire:click="runAnyway(@js($profile))">Run anyway</button>
                @endif
            </p>
        </div>
    @endforeach

    {{-- Quiet stats row: each stat is a prominent value over a muted caption. --}}
    <section class="stats">
        <div class="stat">
            <h1 @class(['stat-value', 'stat-state', 'tone-good' => ! $paused, 'tone-attention' => $paused])>{{ $paused ? 'Paused' : 'Running' }}</h1>
            <p class="stat-label">
                @php $activeCount = $repositories->sum(fn ($r) => $r->runs->count()); @endphp
                @if ($paused)
                    {{ $pauseReason ?? 'No new issues will be picked up. Runs already in progress will finish.' }}
                @elseif ($activeCount)
                    {{ $activeCount }} {{ Str::plural('issue', $activeCount) }} on the line right now.
                @else
                    Nothing on the line. Label an issue “ready-for-agent” to start one.
                @endif
            </p>
        </div>

        @foreach ($gauges as $profile => $gauge)
            <div @class(['stat', 'is-held' => $gauge['held'] ?? false]) wire:key="gauge-{{ $profile }}" aria-label="Claude usage for the {{ $profile }} profile">
                <div class="stat-value">
                    <strong class="stat-name">{{ $profile }}</strong>
                    @if ($gauge['held'] ?? false)<span class="stat-flag tone-bad">Held</span>@endif
                    @foreach (['five_hour' => '5h', 'seven_day' => 'week'] as $key => $label)
                        @if ($window = $gauge[$key] ?? null)
                            <span class="gauge" title="{{ $label }} {{ $window['percent'] }}%, holds at {{ $window['limit'] }}%. {{ ucfirst($window['note']) }}">
                                <span class="gauge-label">{{ $label }}</span>
                                <span class="gauge-bar tone-{{ $window['tone'] }}">
                                    <span style="width: {{ min(100, $window['percent']) }}%"></span>
                                    <i style="left: {{ $window['limit'] }}%"></i>
                                </span>
                                <span class="tone-{{ $window['tone'] }}">{{ $window['percent'] }}%</span>
                            </span>
                        @else
                            <span class="gauge" title="No {{ $label }} usage reported yet">
                                <span class="gauge-label">{{ $label }}</span>
                                <span class="gauge-bar"></span>
                                <span class="tone-quiet">—</span>
                            </span>
                        @endif
                    @endforeach
                </div>
                <p class="stat-label">
                    {{ $gauge ? 'as of '.$gauge['as_of'] : 'usage unknown' }}
                    @if ($tokens = $tokensByProfile[$profile] ?? null)
                        · {{ Number::abbreviate($tokens['in'], maxPrecision: 1) }} in / {{ Number::abbreviate($tokens['out'], maxPrecision: 1) }} out today
                    @endif
                </p>
            </div>
        @endforeach

        <div class="stat">
            <div class="stat-value">${{ number_format($spentToday, 2) }}</div>
            <form wire:submit="saveGlobalBudget" class="stat-label stat-form">
                <label for="globalBudget">spent today, daily limit $</label>
                <input id="globalBudget" type="number" step="1" min="0" placeholder="none" wire:model="globalBudget">
                <button class="btn-link" type="submit">Save</button>
            </form>
        </div>

        <button wire:click="togglePause" class="btn stats-action {{ $paused ? 'btn-go' : 'btn-halt' }}">
            {{ $paused ? 'Resume Foreman' : 'Pause Foreman' }}
        </button>
    </section>

    <div class="dashboard-columns">
    <div>
    <h2 class="section-title">Repositories</h2>
    @forelse ($repositories as $repo)
        @php
            $byStation = $repo->runs->groupBy(fn ($run) => $run->status->value);
            $finishedToday = $repo->runs()
                ->where('created_at', '>=', now()->startOfDay())
                ->whereNotIn('status', \App\Enums\RunStatus::activeValues())
                ->get()
                ->countBy(fn ($run) => $run->status->value);
            $overBudget = $repo->enabled && $repo->budgetReachedToday();
            $running = $repo->enabled && ! $paused && ! $overBudget && ! isset($heldProfiles[$repo->profileName()]);
        @endphp

        <article @class(['line', 'is-running' => $running]) wire:key="repo-{{ $repo->id }}">
            <div class="line-head">
                <div>
                    <h2 class="line-name"><a href="{{ route('repositories.edit', $repo) }}" wire:navigate>{{ $repo->name }}</a></h2>
                    {{-- data-next sits outside the ignored countdown so each re-render can move it --}}
                    <p class="line-meta" title="{{ $repo->github_repo }} · {{ $repo->model }} · {{ $repo->profileName() }}" data-next="{{ ($repo->last_polled_at?->addMinutes($repo->poll_interval_minutes) ?? now())->timestamp }}">
                        <span title="Merges into {{ $repo->base_branch }}">{{ $repo->github_repo }}</span>
                        <span>{{ $repo->model }}</span>
                        <span title="Claude profile">{{ $repo->profileName() }}</span>
                        @if ($running)
                            <span class="line-countdown" title="Next check for ready issues, every {{ $repo->poll_interval_minutes }} min" wire:ignore x-data="countdown"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.25" /><path d="M8 4.5V8l2.25 1.5" /></svg><span x-text="text"></span></span>
                        @endif
                    </p>
                </div>

                <div class="line-controls">
                    <div class="stepper" role="group" aria-label="Issues at a time for {{ $repo->name }}" title="{{ $repo->concurrency }} at a time">
                        <button wire:click="changeConcurrency({{ $repo->id }}, -1)" @disabled($repo->concurrency <= 1) aria-label="Fewer at a time">−</button>
                        <span>{{ $repo->concurrency }}</span>
                        <button wire:click="changeConcurrency({{ $repo->id }}, 1)" @disabled($repo->concurrency >= 8) aria-label="More at a time">+</button>
                    </div>
                    @php $checkTargets = "checkNow({$repo->id})".($repo->enabled ? '' : ", toggleRepository({$repo->id})"); @endphp
                    <button class="btn btn-icon" wire:click="checkNow({{ $repo->id }})" wire:loading.attr="disabled" wire:loading.class="is-busy" wire:target="{{ $checkTargets }}" @disabled(! $repo->enabled || $paused) aria-label="Check now" title="Check now">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13.25 8a5.25 5.25 0 1 1-1.54-3.71" /><path d="M13.25 2.5v3h-3" /></svg>
                    </button>
                    <button wire:click="toggleRepository({{ $repo->id }})" class="btn {{ $repo->enabled ? 'btn-halt' : 'btn-go' }}">
                        {{ $repo->enabled ? 'Stop' : 'Start' }}
                    </button>
                    <button type="button" wire:click="$set('editing', {{ $repo->id }})" class="btn btn-quiet btn-icon btn-settings" aria-label="Settings for {{ $repo->name }}" title="Settings">
                        {{-- Feather "settings" icon (MIT) --}}
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" /></svg>
                    </button>
                </div>
            </div>

            @if ($overBudget)
                <p class="line-notice">Daily limit of ${{ number_format((float) $repo->daily_budget, 2) }} reached, paused until tomorrow.</p>
            @endif

            {{-- A stopped or over-limit repository keeps its line only while runs in progress finish --}}
            @if (($repo->enabled && ! $overBudget) || $repo->runs->isNotEmpty())
            <div class="track" aria-label="Issues currently being worked on">
                @foreach ($stations as $station)
                    <div class="station">
                        <div class="station-load">
                            @foreach ($byStation->get($station->value, collect()) as $run)
                                <a href="{{ route('runs.show', $run) }}" wire:navigate
                                   title="{{ $run->issue_title }}"
                                   @class([
                                       'piece',
                                       'is-waiting' => $station === \App\Enums\RunStatus::Queued,
                                       'is-moving' => in_array($station, [\App\Enums\RunStatus::Working, \App\Enums\RunStatus::Checking], true),
                                   ])>#{{ $run->issue_number }}</a>
                            @endforeach
                        </div>
                        <span class="station-name">{{ $station->label() }}</span>
                    </div>
                @endforeach
            </div>
            @endif

            @if ($repo->enabled || $finishedToday->isNotEmpty())
            <div class="bin">
                <span class="bin-title">Done today</span>
                @if ($finishedToday->isEmpty())
                    <span class="hint">Nothing yet.</span>
                @else
                    <dl>
                        @foreach (['pr_ready', 'watching_pr', 'pr_opened', 'needs_human', 'failed', 'cancelled'] as $value)
                            @if ($finishedToday->get($value))
                                <div>
                                    <dt class="tone-{{ \App\Enums\RunStatus::from($value)->tone() }}">{{ $finishedToday->get($value) }}</dt>
                                    <dd>{{ \App\Enums\RunStatus::from($value)->label() }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                @endif
            </div>
            @endif

            @if ($repo->last_error)
                <p class="line-error">Last check failed: {{ Str::limit($repo->last_error, 240) }}</p>
            @endif
        </article>
    @empty
        <div class="empty">
            <h2>Add your first repository</h2>
            <p>Point Foreman at a local checkout. It will watch that repository for issues labelled “ready-for-agent” and work through them one at a time until you raise the limit.</p>
            <button type="button" wire:click="$set('adding', true)" class="btn btn-go">Add repository</button>
        </div>
    @endforelse
    @if ($repositories->isNotEmpty())
        <button type="button" wire:click="$set('adding', true)" class="line-add">+ Add Repository</button>
    @endif
    </div>

    @if ($recentRuns->isNotEmpty())
        <div>
        <h2 class="section-title">Recent runs</h2>
        <div class="table-wrap">
            <table class="runs">
                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Status</th>
                        <th class="num">Turns</th>
                        <th class="num">Cost</th>
                        <th class="num">Tokens in / out</th>
                        <th class="num">Time</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentRuns as $run)
                        <tr wire:key="run-{{ $run->id }}">
                            <td>
                                <a href="{{ route('runs.show', $run) }}" wire:navigate>#{{ $run->issue_number }}</a><span class="run-repo">{{ $run->repository->name }}</span>
                                <span class="issue-title">{{ $run->issue_title }}</span>
                                @if ($run->status === \App\Enums\RunStatus::Failed && $run->error)
                                    <span class="run-error" title="{{ $run->error }}">{{ $run->error }}</span>
                                @endif
                            </td>
                            <td><span class="status tone-{{ $run->status->tone() }}">{{ $run->statusLabel() }}</span></td>
                            <td class="num">{{ $run->num_turns ?? '' }}</td>
                            <td class="num">{{ $run->cost_usd > 0 ? '$'.number_format($run->cost_usd, 2) : '' }}</td>
                            <td class="num">{{ $run->input_tokens !== null ? Number::abbreviate($run->inputTokens(), maxPrecision: 1).' / '.Number::abbreviate($run->output_tokens, maxPrecision: 1) : '' }}</td>
                            <td class="num">{{ $run->duration() }}</td>
                            <td class="num">
                                @if ($run->pr_url)
                                    <a href="{{ $run->pr_url }}" target="_blank" rel="noopener">PR</a>
                                @elseif ($run->status->isActive())
                                    <button class="btn btn-quiet" wire:click="cancelRun({{ $run->id }})" wire:confirm="Stop working on #{{ $run->issue_number }}?">Cancel</button>
                                @elseif (in_array($run->status, [\App\Enums\RunStatus::Failed, \App\Enums\RunStatus::Cancelled, \App\Enums\RunStatus::NeedsHuman], true))
                                    <button class="btn btn-quiet" wire:click="retryRun({{ $run->id }})">Retry</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>
    @endif
    </div>

    @if ($adding || $editingRepository)
        <div class="modal-backdrop" wire:key="repository-modal" x-on:keydown.escape.window="$wire.closeModal()">
            <div class="modal" role="dialog" aria-modal="true" aria-label="{{ $editingRepository ? "{$editingRepository->name} settings" : 'Add repository' }}">
                <button type="button" class="btn btn-quiet btn-icon modal-close" wire:click="closeModal" aria-label="Close" title="Close">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
                </button>
                <livewire:repository-form :repository="$editingRepository" :key="'repository-form-'.($editingRepository?->id ?? 'new')" />
            </div>
        </div>
    @endif
</div>
