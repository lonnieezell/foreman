<div>
    <div class="run-head">
        <h1>Settings</h1>
    </div>

    @if ($flash)
        <div class="notice" wire:key="flash-{{ md5($flash) }}"><p>{{ $flash }}</p></div>
    @endif

    <form wire:submit="save" class="form">
        <section class="fieldset">
            <h2>Spend</h2>
            <div class="field-row">
                <div class="field">
                    <label for="global_daily_budget">Daily limit across all repositories (USD)</label>
                    <input id="global_daily_budget" type="number" step="0.5" min="0" wire:model="global_daily_budget" placeholder="No limit">
                    <span class="hint">No new runs start once today's spend reaches this.</span>
                    @error('global_daily_budget') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </section>

        <section class="fieldset">
            <h2>Usage holds</h2>
            <p class="hint">When to hold new runs on a Claude profile, from its /usage numbers. Leave a field empty to use the default shown.</p>
            <div class="field-row field-row-aligned">
                <div class="field">
                    <label for="five_hour_ceiling">5-hour ceiling (%)</label>
                    <input id="five_hour_ceiling" type="number" min="1" max="100" wire:model="five_hour_ceiling" placeholder="{{ $defaults['five_hour_ceiling'] }}">
                    <span class="hint">Hold once the 5-hour window reaches this.</span>
                    @error('five_hour_ceiling') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="weekly_pace_margin">Weekly pace margin (%)</label>
                    <input id="weekly_pace_margin" type="number" min="0" max="100" wire:model="weekly_pace_margin" placeholder="{{ $defaults['weekly_pace_margin'] }}">
                    <span class="hint">Hold once weekly usage is this far ahead of how much of the week has gone.</span>
                    @error('weekly_pace_margin') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="weekly_cap">Weekly cap (%)</label>
                    <input id="weekly_cap" type="number" min="1" max="100" wire:model="weekly_cap" placeholder="{{ $defaults['weekly_cap'] }}">
                    <span class="hint">Hold at this weekly usage whatever the pace.</span>
                    @error('weekly_cap') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="probe_minutes">Refresh usage after (minutes)</label>
                    <input id="probe_minutes" type="number" min="1" wire:model="probe_minutes" placeholder="{{ $defaults['probe_minutes'] }}">
                    <span class="hint">Check a profile's usage again before starting runs if it's older than this.</span>
                    @error('probe_minutes') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </section>

        <section class="fieldset">
            <h2>PR watching</h2>
            <p class="hint">For repositories with Watch PRs on. Leave a field empty to use the default shown.</p>
            <div class="field-row field-row-aligned">
                <div class="field">
                    <label for="pr_max_rounds">Fix-up rounds per PR</label>
                    <input id="pr_max_rounds" type="number" min="0" max="10" wire:model="pr_max_rounds" placeholder="{{ $defaults['pr_max_rounds'] }}">
                    <span class="hint">Then it's handed to a human. 0 hands it over straight away.</span>
                    @error('pr_max_rounds') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="pr_quiet_minutes">Review bots quiet for (minutes)</label>
                    <input id="pr_quiet_minutes" type="number" min="1" wire:model="pr_quiet_minutes" placeholder="{{ $defaults['pr_quiet_minutes'] }}">
                    <span class="hint">A round starts once no check is pending and bots have been quiet this long.</span>
                    @error('pr_quiet_minutes') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="pr_timeout_minutes">Start a round anyway after (minutes)</label>
                    <input id="pr_timeout_minutes" type="number" min="1" wire:model="pr_timeout_minutes" placeholder="{{ $defaults['pr_timeout_minutes'] }}">
                    <span class="hint">Measured from the push, even if checks are still pending.</span>
                    @error('pr_timeout_minutes') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </section>

        <div>
            <button type="submit" class="btn btn-go">Save settings</button>
        </div>
    </form>
</div>
