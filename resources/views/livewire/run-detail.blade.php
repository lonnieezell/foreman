<div @if ($run->status->isActive()) wire:poll.2s @endif>
    @if ($flash)
        <div class="notice"><p>{{ $flash }}</p></div>
    @endif

    <div class="run-head">
        <div>
            <p class="line-meta"><a href="{{ route('dashboard') }}" wire:navigate>{{ $run->repository->name }}</a></p>
            <h1>#{{ $run->issue_number }} {{ $run->issue_title }}</h1>
            <p style="margin-top:.4rem">
                <span class="status tone-{{ $run->status->tone() }}">
                    {{ $run->statusLabel() }}
                </span>
            </p>
        </div>

        <div class="line-controls">
            @if ($run->issue_url)
                <a class="btn" href="{{ $run->issue_url }}" target="_blank" rel="noopener">Open issue</a>
            @endif
            @if ($run->pr_url)
                <a class="btn btn-go" href="{{ $run->pr_url }}" target="_blank" rel="noopener">Open PR</a>
            @endif
            @if ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->session_id && $run->worktree_path && ! $run->pr_url)
                <button class="btn btn-go" wire:click="continueRun" wire:confirm="Have you answered on the issue? The agent will pick up where it stopped and read your reply.">Continue after my reply</button>
            @endif
            @if ($run->status->isActive())
                <button class="btn btn-halt" wire:click="cancel" wire:confirm="Stop working on #{{ $run->issue_number }}?">Cancel run</button>
            @elseif ($run->status === \App\Enums\RunStatus::WatchingPr)
                <button class="btn btn-halt" wire:click="cancel" wire:confirm="Stop watching the PR for #{{ $run->issue_number }}? The PR stays open.">Stop watching</button>
            @elseif (in_array($run->status, [\App\Enums\RunStatus::Failed, \App\Enums\RunStatus::Cancelled, \App\Enums\RunStatus::NeedsHuman], true) && ! $run->pr_url)
                <button class="btn" wire:click="retry">Start over</button>
            @endif
        </div>
    </div>

    @if ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url)
        <div class="notice">
            <p>The PR still has failing checks or open review threads that the agent couldn’t settle. It’s yours from here: see the latest comment on the PR.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman)
        <div class="notice">
            <p>The agent stopped and asked for help on the issue. Reply there, then choose <strong>Continue after my reply</strong> to resume with everything it already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @endif

    @if ($run->error)
        <div class="notice notice-halt"><p>{{ $run->error }}</p></div>
    @endif

    <dl class="facts">
        <div><dt>Model</dt><dd>{{ $run->model }}</dd></div>
        <div><dt>Turns</dt><dd>{{ $run->num_turns ?? '–' }}</dd></div>
        <div><dt>Cost</dt><dd>${{ number_format($run->cost_usd, 2) }}</dd></div>
        @if ($run->input_tokens !== null)
            <div><dt>Tokens in</dt><dd title="{{ number_format($run->input_tokens) }} new, {{ number_format($run->cache_read_tokens) }} cache read, {{ number_format($run->cache_write_tokens) }} cache write">{{ Number::abbreviate($run->inputTokens(), maxPrecision: 1) }}</dd></div>
            <div><dt>Tokens out</dt><dd title="{{ number_format($run->output_tokens) }}">{{ Number::abbreviate($run->output_tokens, maxPrecision: 1) }}</dd></div>
        @endif
        <div><dt>Time</dt><dd>{{ $run->duration() ?? '–' }}</dd></div>
        <div><dt>Branch</dt><dd>{{ $run->branch }}</dd></div>
        @if ($run->previousRun)
            <div><dt>Resumed from</dt><dd><a href="{{ route('runs.show', $run->previousRun) }}" wire:navigate>run {{ $run->previous_run_id }}</a></dd></div>
        @elseif ($run->resume_session_id)
            <div><dt>Resumed from</dt><dd>an earlier run</dd></div>
        @endif
    </dl>

    @if ($run->worktree_path)
        <p class="hint" style="margin-bottom:.75rem">Worktree: <code>{{ $run->worktree_path }}</code></p>
    @endif

    <pre class="log" x-data x-init="$el.scrollTop = $el.scrollHeight; Livewire.hook('morph.updated', ({ el }) => { if (el === $el) $el.scrollTop = $el.scrollHeight })">{{ $log ?: 'Waiting for the first log line…' }}</pre>
</div>
