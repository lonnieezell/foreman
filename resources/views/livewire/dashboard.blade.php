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

    <section class="overview">
        <div>
            <h1>{{ $paused ? 'Paused' : 'Running' }}</h1>
            <p class="state">
                @php $activeCount = $repositories->sum(fn ($r) => $r->runs->count()); @endphp
                @if ($paused)
                    {{ $pauseReason ?? 'No new issues will be picked up. Runs already in progress will finish.' }}
                @elseif ($activeCount)
                    {{ $activeCount }} {{ Str::plural('issue', $activeCount) }} on the line right now.
                @else
                    Nothing on the line. Label an issue “ready-for-agent” to start one.
                @endif
            </p>
            @foreach ($usageByProfile as $profile => $summary)
                <p class="hint" wire:key="usage-{{ $profile }}"><strong>{{ $profile }}</strong> · {{ $summary }}</p>
            @endforeach
        </div>

        <div class="overview-controls">
            <div class="spend">
                ${{ number_format($spentToday, 2) }}
                <small>spent today{{ $budget !== null ? ' of $'.number_format($budget, 2) : ', no daily limit' }}</small>
            </div>
            @foreach ($tokensByProfile as $profile => $tokens)
                <div class="spend" wire:key="tokens-{{ $profile }}">
                    {{ Number::abbreviate($tokens['in'], maxPrecision: 1) }} / {{ Number::abbreviate($tokens['out'], maxPrecision: 1) }}
                    <small>{{ $profile }} tokens in / out today</small>
                </div>
            @endforeach
            <form wire:submit="saveGlobalBudget" class="field" style="gap:.2rem">
                <label for="globalBudget" class="hint">Daily limit, all repositories</label>
                <div class="field-inline">
                    <input id="globalBudget" type="number" step="1" min="0" placeholder="None" wire:model="globalBudget" class="input-compact">
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>
            <button wire:click="togglePause" class="btn {{ $paused ? 'btn-go' : 'btn-halt' }}">
                {{ $paused ? 'Resume factory' : 'Pause factory' }}
            </button>
        </div>
    </section>

    @forelse ($repositories as $repo)
        @php
            $byStation = $repo->runs->groupBy(fn ($run) => $run->status->value);
            $finishedToday = $repo->runs()
                ->where('created_at', '>=', now()->startOfDay())
                ->whereNotIn('status', \App\Enums\RunStatus::activeValues())
                ->get()
                ->countBy(fn ($run) => $run->status->value);
            $spent = $repo->spentToday();
            $limit = $repo->daily_budget !== null ? (float) $repo->daily_budget : null;
            $running = $repo->enabled && ! $paused && ! isset($heldProfiles[$repo->profileName()]);
        @endphp

        <article @class(['line', 'is-running' => $running]) wire:key="repo-{{ $repo->id }}">
            <div class="line-head">
                <div>
                    <h2 class="line-name"><a href="{{ route('repositories.edit', $repo) }}" wire:navigate>{{ $repo->name }}</a></h2>
                    <p class="line-meta">
                        {{ $repo->github_repo }} into {{ $repo->base_branch }}, {{ $repo->model }} on the {{ $repo->profileName() }} profile, checks every {{ $repo->poll_interval_minutes }} min
                        @if ($repo->last_polled_at)
                            (last {{ $repo->last_polled_at->diffForHumans() }})
                        @endif
                    </p>
                </div>

                <div class="line-controls">
                    <div class="stepper" role="group" aria-label="Issues at a time for {{ $repo->name }}">
                        <button wire:click="changeConcurrency({{ $repo->id }}, -1)" @disabled($repo->concurrency <= 1) aria-label="Fewer at a time">−</button>
                        <span>{{ $repo->concurrency }} at a time</span>
                        <button wire:click="changeConcurrency({{ $repo->id }}, 1)" @disabled($repo->concurrency >= 8) aria-label="More at a time">+</button>
                    </div>
                    @php $checkTargets = "checkNow({$repo->id})".($repo->enabled ? '' : ", toggleRepository({$repo->id})"); @endphp
                    <button class="btn" wire:click="checkNow({{ $repo->id }})" wire:loading.attr="disabled" wire:target="{{ $checkTargets }}" @disabled(! $repo->enabled || $paused)>
                        <span wire:loading.remove wire:target="{{ $checkTargets }}">Check now</span>
                        <span wire:loading wire:target="{{ $checkTargets }}">Checking…</span>
                    </button>
                    <button wire:click="toggleRepository({{ $repo->id }})" class="btn {{ $repo->enabled ? 'btn-halt' : 'btn-go' }}">
                        {{ $repo->enabled ? 'Stop' : 'Start' }}
                    </button>
                </div>
            </div>

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

                <div class="bin">
                    <span class="bin-title">Done today</span>
                    @if ($finishedToday->isEmpty())
                        <p class="hint" style="margin-top:.3rem">Nothing yet.</p>
                    @else
                        <dl>
                            @foreach (['pr_opened', 'needs_human', 'failed', 'cancelled'] as $value)
                                @if ($finishedToday->get($value))
                                    <dt class="tone-{{ \App\Enums\RunStatus::from($value)->tone() }}">{{ $finishedToday->get($value) }}</dt>
                                    <dd>{{ \App\Enums\RunStatus::from($value)->label() }}</dd>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>

            <div class="meter">
                <span>
                    ${{ number_format($spent, 2) }} today{{ $limit !== null ? ' of $'.number_format($limit, 2) : '' }}
                    @if ($limit !== null && $spent >= $limit)
                        <strong class="tone-bad">limit reached, paused until tomorrow</strong>
                    @endif
                </span>
                @if ($limit)
                    <div @class(['meter-bar', 'is-full' => $spent >= $limit]) aria-hidden="true">
                        <span style="width: {{ min(100, round($spent / $limit * 100)) }}%"></span>
                    </div>
                @endif
                <a href="{{ route('repositories.edit', $repo) }}" wire:navigate class="btn btn-quiet" style="margin-left:auto">Settings</a>
            </div>

            @if ($repo->last_error)
                <p class="line-error">Last check failed: {{ Str::limit($repo->last_error, 240) }}</p>
            @endif
        </article>
    @empty
        <div class="empty">
            <h2>Add your first repository</h2>
            <p>Point the factory at a local checkout. It will watch that repository for issues labelled “ready-for-agent” and work through them one at a time until you raise the limit.</p>
            <a href="{{ route('repositories.create') }}" wire:navigate class="btn btn-go">Add repository</a>
        </div>
    @endforelse

    @if ($recentRuns->isNotEmpty())
        <h2 class="section-title">Recent runs</h2>
        <div class="table-wrap">
            <table class="runs">
                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Repository</th>
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
                                <a href="{{ route('runs.show', $run) }}" wire:navigate>#{{ $run->issue_number }}</a>
                                <span class="issue-title">{{ $run->issue_title }}</span>
                                @if ($run->status === \App\Enums\RunStatus::Failed && $run->error)
                                    <span class="run-error" title="{{ $run->error }}">{{ $run->error }}</span>
                                @endif
                            </td>
                            <td>{{ $run->repository->name }}</td>
                            <td><span class="status tone-{{ $run->status->tone() }}">{{ $run->cancel_requested && $run->status->isActive() ? 'Cancelling' : $run->status->label() }}</span></td>
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
    @endif
</div>
