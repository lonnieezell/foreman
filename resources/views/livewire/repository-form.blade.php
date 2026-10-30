<div>
    <div class="run-head">
        <h1>{{ $repository ? $repository->name : 'Add repository' }}</h1>
        @if ($repository)
            <button class="btn btn-halt" wire:click="delete" wire:confirm="Remove {{ $repository->name }} and its run history from the factory? Nothing on GitHub is touched.">Remove from factory</button>
        @endif
    </div>

    @if (session('label_warning'))
        <div class="notice notice-halt"><p>{{ session('label_warning') }}</p></div>
    @endif

    <form wire:submit="save" class="form">
        <section class="fieldset">
            <h2>Where the code lives</h2>

            <div class="field">
                <label for="local_path">Local checkout</label>
                <div class="field-inline">
                    <input id="local_path" type="text" wire:model="local_path" placeholder="~/code/vault-app">
                    <button type="button" class="btn" wire:click="detect">Detect</button>
                </div>
                <span class="hint">Runs happen in separate git worktrees, so your own checkout is never touched.</span>
                @error('local_path') <span class="error">{{ $message }}</span> @enderror
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="github_repo">GitHub repository</label>
                    <input id="github_repo" type="text" wire:model="github_repo" placeholder="owner/name">
                    @error('github_repo') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="name">Name in the factory</label>
                    <input id="name" type="text" wire:model="name">
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="base_branch">Branch PRs target</label>
                    <input id="base_branch" type="text" wire:model="base_branch">
                    @error('base_branch') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </section>

        <section class="fieldset">
            <h2>Pace and spend</h2>
            <div class="field-row">
                <div class="field">
                    <label for="concurrency">Issues at a time</label>
                    <input id="concurrency" type="number" min="1" max="8" wire:model="concurrency">
                    @error('concurrency') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="poll_interval_minutes">Check for issues every (minutes)</label>
                    <input id="poll_interval_minutes" type="number" min="1" wire:model="poll_interval_minutes">
                    @error('poll_interval_minutes') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="daily_budget">Daily limit (USD)</label>
                    <input id="daily_budget" type="number" step="0.5" min="0" wire:model="daily_budget" placeholder="No limit">
                    @error('daily_budget') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="model">Model</label>
                    <select id="model" wire:model="model">
                        <option value="haiku">Haiku</option>
                        <option value="sonnet">Sonnet</option>
                        <option value="opus">Opus</option>
                    </select>
                </div>
                <div class="field">
                    <label for="claude_profile">Claude profile</label>
                    <select id="claude_profile" wire:model="claude_profile">
                        @empty(config('factory.claude_profiles'))
                            <option value="">Default (~/.claude)</option>
                        @endempty
                        @foreach (array_keys(config('factory.claude_profiles')) as $profile)
                            <option value="{{ $profile }}">{{ $profile }}</option>
                        @endforeach
                    </select>
                    @error('claude_profile') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="max_turns">Max turns per issue</label>
                    <input id="max_turns" type="number" min="1" wire:model="max_turns">
                    @error('max_turns') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="max_minutes">Max minutes per issue</label>
                    <input id="max_minutes" type="number" min="1" wire:model="max_minutes">
                    @error('max_minutes') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="hint">Spend is what Claude Code reports per run. On a subscription it’s an estimate, but still useful for comparing runs.</p>
        </section>

        <section class="fieldset">
            <h2>Labels</h2>
            <p class="hint">These are created on GitHub when you save. Add the first one to an issue to send it to the factory.</p>
            <div class="field-row">
                <div class="field">
                    <label for="ready_label">Ready for the agent</label>
                    <input id="ready_label" type="text" wire:model="ready_label">
                    @error('ready_label') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="working_label">Being worked on</label>
                    <input id="working_label" type="text" wire:model="working_label">
                    @error('working_label') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="human_label">Needs a human</label>
                    <input id="human_label" type="text" wire:model="human_label">
                    @error('human_label') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </section>

        <section class="fieldset">
            <h2>How the agent works</h2>

            <div class="field">
                <label for="setup_script">Setup script</label>
                <textarea id="setup_script" rows="4" wire:model="setup_script"></textarea>
                <span class="hint">Runs in each new worktree before the agent starts. It stops at the first failing line.</span>
                @error('setup_script') <span class="error">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label for="allowed_tools">Allowed tools</label>
                <textarea id="allowed_tools" rows="3" wire:model="allowed_tools"></textarea>
                <span class="hint">Passed to <code>--allowedTools</code>. Anything not listed is refused.</span>
                @error('allowed_tools') <span class="error">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label for="prompt_template">Instructions</label>
                <textarea id="prompt_template" rows="16" wire:model="prompt_template"></textarea>
                <span class="hint">
                    Placeholders: <code>@{{issue}}</code> <code>@{{branch}}</code> <code>@{{base}}</code> <code>@{{repo}}</code> <code>@{{human_label}}</code>.
                    <button type="button" class="btn btn-quiet" style="padding:0" wire:click="resetPrompt">Restore default</button>
                </span>
                @error('prompt_template') <span class="error">{{ $message }}</span> @enderror
            </div>

            <label class="check">
                <input type="checkbox" wire:model="cleanup_worktrees">
                Remove the worktree once a PR is open
            </label>
        </section>

        <div>
            <button type="submit" class="btn btn-go">{{ $repository ? 'Save changes' : 'Add repository' }}</button>
            <a href="{{ route('dashboard') }}" wire:navigate class="btn btn-quiet">Cancel</a>
        </div>
    </form>
</div>
