@php
    // Which section each field belongs to, for the side nav and the unsaved-changes footer
    $sections = [
        'repo' => ['Repository', ['local_path', 'github_repo', 'name', 'base_branch']],
        'limits' => ['Model & limits', ['model', 'effort', 'claude_profile', 'daily_budget', 'max_budget_per_run', 'concurrency', 'poll_interval_minutes', 'max_turns', 'max_minutes']],
        'labels' => ['Labels', ['ready_label', 'working_label', 'human_label']],
        'scripts' => ['Scripts', ['setup_script', 'verify_script']],
        'perms' => ['Permissions', ['allowed_tools', 'locked_paths']],
        'instructions' => ['Instructions', ['prompt_template']],
        'after' => ['After the PR', ['watch_prs', 'cleanup_worktrees', 'sandbox']],
    ];
    $fields = collect($sections)->flatMap(fn ($section) => array_fill_keys($section[1], $section[0]));
    $stackNames = ['php' => 'PHP', 'typescript' => 'TypeScript', 'rust' => 'Rust', 'python' => 'Python'];
@endphp

<div class="settings" x-data="repoSettings(@js($fields))">
    <div class="settings-head">
        @if ($repository)
            <div class="settings-title">
                <h1>{{ $repository->name }}</h1>
                @if ($repository->archived_at)
                    <span class="settings-pill tone-quiet">Archived</span>
                @elseif ($repository->enabled)
                    <span class="settings-pill tone-good">Active</span>
                @else
                    <span class="settings-pill tone-quiet">Stopped</span>
                @endif
            </div>
            <p class="line-meta settings-summary">
                <span>{{ $repository->github_repo }} → {{ $repository->base_branch }}</span>
                <span>{{ App\Models\Repository::MODELS[$repository->model] ?? $repository->model }} · {{ $repository->effort ? App\Models\Repository::EFFORTS[$repository->effort].' effort' : 'Default effort' }}</span>
                <span>{{ $repository->concurrency }} {{ Str::plural('issue', $repository->concurrency) }} at a time · every {{ $repository->poll_interval_minutes }} min</span>
            </p>
        @else
            <h1>Add repository</h1>
        @endif
        <a class="doc-link" href="{{ route('docs', 'repository-settings') }}" wire:navigate>What these settings do</a>
    </div>

    @if (session('label_warning'))
        <div class="notice notice-halt"><p>{{ session('label_warning') }}</p></div>
    @endif

    <div class="settings-body">
        <nav class="settings-nav" aria-label="Settings sections">
            @foreach ($sections as $id => [$title])
                <a href="#settings-{{ $id }}" x-on:click.prevent="go('{{ $id }}')" x-bind:class="{ 'is-current': current === '{{ $id }}' }">
                    {{ $title }}
                    @if ($id === 'perms' && $this->hasLegacyAllowedTools)
                        <span class="settings-nav-dot" title="Needs attention"><span class="sr-only">Needs attention</span></span>
                    @elseif ($id === 'instructions' && $this->promptIsEdited)
                        <span class="settings-nav-flag">Edited</span>
                    @endif
                </a>
            @endforeach
            @if ($repository)
                <a href="#settings-danger" x-on:click.prevent="go('danger')" x-bind:class="{ 'is-current': current === 'danger' }" class="settings-nav-danger">Archive or remove</a>
            @endif
        </nav>

        <form wire:submit="save" class="settings-form">
            <section id="settings-repo" data-section="repo" class="fieldset">
                <div class="settings-section-head">
                    <h2>Repository</h2>
                    <p class="hint">Runs happen in separate git worktrees, so your own checkout is never touched.</p>
                </div>

                <div class="field">
                    <label for="local_path">Local checkout</label>
                    <div class="field-inline">
                        <input id="local_path" type="text" class="input-mono" wire:model="local_path" placeholder="~/code/vault-app">
                        <button type="button" class="btn" wire:click="browse" wire:loading.attr="disabled" wire:target="browse">Browse…</button>
                        <button type="button" class="btn" wire:click="detect">Detect</button>
                    </div>
                    @error('local_path') <span class="error">{{ $message }}</span> @enderror
                </div>

                <div class="field-row field-row-aligned settings-repo-row">
                    <div class="field">
                        <label for="github_repo">GitHub repository</label>
                        <input id="github_repo" type="text" wire:model="github_repo" placeholder="owner/name">
                        @error('github_repo') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="name">Name in Foreman</label>
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

            <section id="settings-limits" data-section="limits" class="fieldset">
                <div class="settings-section-head">
                    <h2>Model &amp; limits</h2>
                    <p class="hint">Spend is what Claude Code reports per run. On a subscription it’s an estimate, but still useful for comparing runs.</p>
                </div>

                <div class="field-row field-row-aligned settings-model-row">
                    <div class="field">
                        <span class="field-label" id="model_label">Model</span>
                        <div class="segmented" role="radiogroup" aria-labelledby="model_label">
                            @foreach (App\Models\Repository::MODELS as $value => $label)
                                <label><input type="radio" name="model" value="{{ $value }}" wire:model="model"><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                        @error('model') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="effort">Effort</label>
                        <select id="effort" wire:model="effort">
                            <option value="">Default</option>
                            @foreach (App\Models\Repository::EFFORTS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('effort') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="claude_profile">Claude profile</label>
                        <select id="claude_profile" wire:model="claude_profile">
                            @empty(config('foreman.claude_profiles'))
                                <option value="">Default (~/.claude)</option>
                            @endempty
                            @foreach (array_keys(config('foreman.claude_profiles')) as $profile)
                                <option value="{{ $profile }}">{{ $profile }}</option>
                            @endforeach
                        </select>
                        @error('claude_profile') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="settings-cards">
                    <div class="settings-card">
                        <h3 class="settings-card-title">Budget</h3>
                        <div class="field-row field-row-aligned">
                            <div class="field">
                                <label for="daily_budget">Per day</label>
                                <div class="input-unit"><span aria-hidden="true">$</span><input id="daily_budget" type="number" step="0.5" min="0" wire:model="daily_budget" placeholder="No limit"></div>
                                @error('daily_budget') <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field">
                                <label for="max_budget_per_run">Per run</label>
                                <div class="input-unit"><span aria-hidden="true">$</span><input id="max_budget_per_run" type="number" step="0.5" min="0" wire:model="max_budget_per_run" placeholder="No limit"></div>
                                @error('max_budget_per_run') <span class="error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <p class="hint tone-attention" x-show="! $wire.daily_budget && ! $wire.max_budget_per_run">No spending cap is set for this repository.</p>
                    </div>

                    <div class="settings-card">
                        <h3 class="settings-card-title">Pace and stop conditions</h3>
                        <div class="field-row field-row-aligned settings-pace">
                            <div class="field">
                                <label for="concurrency">Issues at a time</label>
                                <input id="concurrency" type="number" min="1" max="8" wire:model="concurrency">
                                @error('concurrency') <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field">
                                <label for="poll_interval_minutes">Check for issues every</label>
                                <div class="input-unit"><input id="poll_interval_minutes" type="number" min="1" wire:model="poll_interval_minutes"><span>min</span></div>
                                @error('poll_interval_minutes') <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field">
                                <label for="max_turns">Max turns per issue</label>
                                <div class="input-unit"><input id="max_turns" type="number" min="1" wire:model="max_turns"><span>turns</span></div>
                                @error('max_turns') <span class="error">{{ $message }}</span> @enderror
                            </div>
                            <div class="field">
                                <label for="max_minutes">Max time per issue</label>
                                <div class="input-unit"><input id="max_minutes" type="number" min="1" wire:model="max_minutes"><span>min</span></div>
                                @error('max_minutes') <span class="error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="settings-labels" data-section="labels" class="fieldset">
                <div class="settings-section-head">
                    <h2>Labels</h2>
                    <p class="hint">Created on GitHub when you save. Add the first one to an issue to send it to Foreman.</p>
                </div>
                <div class="label-flow">
                    <div class="field">
                        <label for="ready_label">1 · Ready for the agent</label>
                        <input id="ready_label" type="text" class="input-mono tone-good" wire:model="ready_label">
                        @error('ready_label') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <svg class="label-flow-arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13M11 5l5 5-5 5" /></svg>
                    <div class="field">
                        <label for="working_label">2 · Being worked on</label>
                        <input id="working_label" type="text" class="input-mono" wire:model="working_label">
                        @error('working_label') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <svg class="label-flow-arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10h13M11 5l5 5-5 5" /></svg>
                    <div class="field">
                        <label for="human_label">If stuck · Needs a human</label>
                        <input id="human_label" type="text" class="input-mono tone-attention" wire:model="human_label">
                        @error('human_label') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
            </section>

            <section id="settings-scripts" data-section="scripts" class="fieldset">
                <div class="settings-section-head">
                    <h2>Scripts</h2>
                    @if ($stacks)
                        <p class="hint">Detected stacks: <strong>{{ collect($stacks)->map(fn ($stack) => $stackNames[$stack] ?? $stack)->join(' · ') }}</strong></p>
                    @endif
                </div>
                <div class="settings-scripts">
                    <div class="field">
                        <div class="field-label-row">
                            <label for="setup_script">Setup <span class="field-label-note">· runs in each new worktree</span></label>
                            <button type="button" class="btn btn-quiet btn-icon" x-data="copyFrom('setupScriptPrompt')" x-on:click="copy"
                                    aria-label="Copy a prompt for Claude Code to work out this script"
                                    title="Copy a prompt for Claude Code to work out this script. Paste it into claude in the project."
                                    x-bind:aria-label="copied ? 'Copied' : 'Copy a prompt for Claude Code to work out this script'"
                                    x-bind:title="copied ? 'Copied' : 'Copy a prompt for Claude Code to work out this script. Paste it into claude in the project.'">
                                <svg x-show="! copied" viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 4.5 2 8l3.5 3.5M10.5 4.5 14 8l-3.5 3.5" /></svg>
                                <svg x-show="copied" style="display: none" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5l3.25 3.25L13 5" /></svg>
                            </button>
                        </div>
                        <textarea id="setup_script" rows="5" wire:model="setup_script"></textarea>
                        <span class="hint">It stops at the first failing line.</span>
                        @error('setup_script') <span class="error">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <div class="field-label-row">
                            <label for="verify_script">Verify <span class="field-label-note">· must pass before the agent stops</span></label>
                            <span class="field-label-actions">
                                @if ($stacks)
                                    <button type="button" class="btn btn-quiet btn-link" wire:click="useDetectedChecks">Use detected checks</button>
                                @endif
                                <button type="button" class="btn btn-quiet btn-icon" x-data="copyFrom('verifyScriptPrompt')" x-on:click="copy"
                                        aria-label="Copy a prompt for Claude Code to work out this script"
                                        title="Copy a prompt for Claude Code to work out this script. Paste it into claude in the project."
                                        x-bind:aria-label="copied ? 'Copied' : 'Copy a prompt for Claude Code to work out this script'"
                                        x-bind:title="copied ? 'Copied' : 'Copy a prompt for Claude Code to work out this script. Paste it into claude in the project.'">
                                    <svg x-show="! copied" viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 4.5 2 8l3.5 3.5M10.5 4.5 14 8l-3.5 3.5" /></svg>
                                    <svg x-show="copied" style="display: none" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8.5l3.25 3.25L13 5" /></svg>
                                </button>
                            </span>
                        </div>
                        <textarea id="verify_script" rows="5" wire:model="verify_script"></textarea>
                        <span class="hint">Leave it empty to use the checks of the stacks Foreman finds.</span>
                        @error('verify_script') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
            </section>

            <section id="settings-perms" data-section="perms" class="fieldset">
                <div class="settings-section-head">
                    <h2>Permissions</h2>
                    <p class="hint">Passed to <code>--allowedTools</code>. Anything not listed is refused.</p>
                </div>

                @if ($this->hasLegacyAllowedTools)
                    <div class="settings-alert" role="status">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l9 16H3z" /><path d="M12 10v4M12 17v.01" /></svg>
                        <p>
                            This list has entries from the old default.
                            @if (str_contains($allowed_tools, 'Bash(git:*)'))
                                <code>Bash(git:*)</code> lets the agent run any git command, not just inspect and commit.
                            @endif
                            @if (str_contains($allowed_tools, 'Bash(gh '))
                                The <code>gh</code> entries do nothing now, because the guard hook blocks every <code>gh</code> command.
                            @endif
                        </p>
                        <button type="button" class="btn" wire:click="removeLegacyAllowedTools">Remove them</button>
                    </div>
                @endif

                <div class="field" x-data="toolList(@js(App\Models\Repository::LEGACY_ALLOWED_TOOLS))">
                    <span class="field-label">Allowed tools</span>
                    <div class="tool-groups" x-show="! asText">
                        <template x-for="group in groups" x-bind:key="group.name">
                            <div class="tool-group">
                                <span class="tool-group-name" x-text="group.name"></span>
                                <ul class="chips">
                                    <template x-for="tool in group.tools" x-bind:key="tool.entry">
                                        <li class="chip" x-bind:class="{ 'is-legacy': tool.legacy }" x-bind:title="tool.entry" x-text="tool.label"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                    <div x-show="asText" x-cloak>
                        <label for="allowed_tools" class="sr-only">Allowed tools, separated by commas</label>
                        <textarea id="allowed_tools" rows="4" wire:model="allowed_tools"></textarea>
                    </div>
                    <div class="field-inline" x-show="adding" x-cloak>
                        <label for="new_tool" class="sr-only">New tool</label>
                        <input id="new_tool" type="text" class="input-mono" x-ref="newTool" x-model="draft" x-on:keydown.enter.prevent="add" x-on:keydown.escape.stop="adding = false" placeholder="Bash(make:*)">
                        <button type="button" class="btn" x-on:click="add">Add</button>
                    </div>
                    <div class="link-row">
                        <button type="button" class="btn btn-quiet btn-link" x-on:click="adding = true; $nextTick(() => $refs.newTool.focus())">+ Add tool</button>
                        <button type="button" class="btn btn-quiet btn-link" x-on:click="asText = ! asText" x-text="asText ? 'Show as chips' : 'Edit as text'">Edit as text</button>
                        <button type="button" class="btn btn-quiet" wire:click="resetAllowedTools">Restore default</button>
                    </div>
                    @error('allowed_tools') <span class="error">{{ $message }}</span> @enderror
                </div>

                <div class="field" x-data="pathList">
                    <span class="field-label">Locked paths <span class="field-label-note">· the agent can’t edit these</span></span>
                    <ul class="path-list">
                        <template x-for="(path, i) in paths" x-bind:key="i + path">
                            <li>
                                <code x-text="path"></code>
                                <button type="button" class="btn btn-quiet btn-icon" x-bind:aria-label="'Remove ' + path" x-bind:title="'Remove ' + path" x-on:click="remove(i)">
                                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" /></svg>
                                </button>
                            </li>
                        </template>
                        <li class="path-add">
                            <label for="new_locked_path" class="sr-only">New locked path</label>
                            <input id="new_locked_path" type="text" class="input-mono" x-model="draft" x-on:keydown.enter.prevent="add" placeholder="Add a pattern, like database/migrations/**">
                            <button type="button" class="btn" x-on:click="add">Add</button>
                        </li>
                    </ul>
                    <span class="hint">Relative to the repository root. <code>*</code> matches within a folder and <code>**</code> matches across folders.</span>
                    @error('locked_paths') <span class="error">{{ $message }}</span> @enderror
                </div>
            </section>

            <section id="settings-instructions" data-section="instructions" class="fieldset" x-data="{ mode: 'preview' }">
                <div class="settings-section-head settings-section-head-actions">
                    <div>
                        <h2>Instructions</h2>
                        <p class="hint">The prompt each run starts with. {{ $this->promptIsEdited ? 'Edited from the default.' : 'This is the default.' }}</p>
                    </div>
                    <div class="link-row">
                        @if ($this->promptIsEdited)
                            <button type="button" class="btn" x-on:click="mode = mode === 'diff' ? 'preview' : 'diff'" x-text="mode === 'diff' ? 'Hide comparison' : 'Compare with default'">Compare with default</button>
                        @endif
                        <button type="button" class="btn" x-on:click="mode = mode === 'edit' ? 'preview' : 'edit'; mode === 'edit' && $nextTick(() => $refs.prompt.focus())" x-text="mode === 'edit' ? 'Close editor' : 'Open full editor'">Open full editor</button>
                    </div>
                </div>

                <div class="prompt-preview" x-show="mode === 'preview'">
                    <pre>{{ collect(explode("\n", $prompt_template))->take(4)->implode("\n") }}@if ($this->promptLineCount > 4)
…@endif</pre>
                    <p class="hint">
                        {{ $this->promptLineCount }} {{ Str::plural('line', $this->promptLineCount) }}
                        @if ($this->promptPlaceholders)
                            · uses @foreach ($this->promptPlaceholders as $placeholder)<code>&#123;&#123;{{ $placeholder }}&#125;&#125;</code> @endforeach
                        @endif
                    </p>
                </div>

                <div class="field" x-show="mode === 'edit'" x-cloak>
                    <label for="prompt_template" class="sr-only">Instructions</label>
                    <textarea id="prompt_template" rows="24" x-ref="prompt" wire:model.blur="prompt_template"></textarea>
                    <span class="hint">
                        Placeholders: <code>@{{issue}}</code> <code>@{{issue_text}}</code> <code>@{{branch}}</code> <code>@{{base}}</code> <code>@{{repo}}</code> <code>@{{human_label}}</code>.
                        <button type="button" class="btn btn-quiet" style="padding:0" wire:click="resetPrompt">Restore default</button>
                    </span>
                </div>

                @if ($this->promptIsEdited)
                    <div class="prompt-diff" x-show="mode === 'diff'" x-cloak>
                        @foreach ($this->promptDiff as $line)
                            <div class="diff-{{ $line['type'] }}">@if ($line['type'] === 'added')<span class="sr-only">Added: </span>@elseif ($line['type'] === 'removed')<span class="sr-only">Removed: </span>@endif{{ $line['text'] }}</div>
                        @endforeach
                    </div>
                @endif
                @error('prompt_template') <span class="error">{{ $message }}</span> @enderror
            </section>

            <section id="settings-after" data-section="after" class="fieldset">
                <h2>After the PR</h2>
                <div class="switch-row">
                    <div>
                        <label for="watch_prs">Fix failing checks and review-bot comments</label>
                        <p class="hint">Foreman watches the PR and sends the agent back in. Up to {{ config('foreman.pr_watch.max_rounds') }} rounds, <a href="{{ route('settings') }}" wire:navigate>set in Settings</a>.</p>
                    </div>
                    <input id="watch_prs" type="checkbox" role="switch" class="switch" wire:model="watch_prs">
                </div>
                <div class="switch-row">
                    <div>
                        <label for="cleanup_worktrees">Remove the worktree when done</label>
                        <p class="hint">Once Foreman is finished with the PR. A failed run keeps its worktree.</p>
                    </div>
                    <input id="cleanup_worktrees" type="checkbox" role="switch" class="switch" wire:model="cleanup_worktrees">
                </div>
                <div class="switch-row">
                    <div>
                        <label for="sandbox">Sandbox the agent’s shell commands</label>
                        <p class="hint">macOS only. Limits writes to the worktree and the network to an allowlist. <a href="{{ route('docs', 'repository-settings') }}#sandbox">What’s limited?</a></p>
                    </div>
                    <input id="sandbox" type="checkbox" role="switch" class="switch" wire:model="sandbox">
                </div>
            </section>

            @if ($repository)
                <section id="settings-danger" data-section="danger" class="fieldset settings-danger">
                    <h2>Archive or remove</h2>
                    <div class="settings-danger-row">
                        <p class="hint">Archive stops the repository and hides it from the dashboard, but keeps its settings and history. Remove deletes it and its run history from Foreman. Nothing on GitHub is touched.</p>
                        @if ($repository->hasWorkInFlight())
                            <button type="button" class="btn" disabled title="Finish or cancel its runs and watched PRs first">Archive</button>
                        @else
                            <button type="button" class="btn" wire:click="archive" title="Stop it and hide it from the dashboard, keeping its settings">Archive</button>
                        @endif
                        <button type="button" class="btn btn-halt" wire:click="delete" wire:confirm="Remove {{ $repository->name }} and its run history from Foreman? Nothing on GitHub is touched.">Remove from Foreman</button>
                    </div>
                </section>
            @endif

            <div class="settings-footer">
                <p class="settings-unsaved" x-show="changed.length" x-cloak><span class="settings-nav-dot" aria-hidden="true"></span><span x-text="summary"></span></p>
                <p class="hint" x-show="! changed.length">No unsaved changes</p>
                <button type="button" class="btn" x-bind:disabled="! changed.length" x-on:click="discard">Discard</button>
                <button type="submit" class="btn btn-go">{{ $repository ? 'Save changes' : 'Add repository' }}</button>
            </div>
        </form>
    </div>
</div>
