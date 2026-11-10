<div @if ($run->status->isActive()) wire:poll.2s @endif>
    @if ($flash)
        <div class="notice"><p>{{ $flash }}</p></div>
    @endif

    @if (count($attempts) > 1)
        <nav class="attempts" aria-label="Attempts">
            <span class="attempts-title">Attempts</span>
            @foreach ($attempts as $attempt)
                <a href="{{ route('runs.show', $attempt['run']) }}" wire:navigate @class(['attempt', 'status', 'tone-'.$attempt['run']->status->tone(), 'is-current' => $attempt['run']->is($run)]) @if ($attempt['run']->is($run)) aria-current="page" @endif>{{ $attempt['label'] }}</a>
            @endforeach
        </nav>
    @endif

    <div class="run-head">
        <div>
            <p class="line-meta"><a href="{{ route('dashboard') }}" wire:navigate>{{ $run->repository->name }}</a></p>
            <h1>#{{ $run->issue_number }} {{ $run->issue_title }}</h1>
            <p style="margin-top:.4rem">
                <span class="status tone-{{ $run->status->tone() }}">
                    {{ $run->statusLabel() }}
                </span>
                @if ($run->status === \App\Enums\RunStatus::NeedsHuman)
                    <a class="doc-link" href="{{ route('docs', 'runs') }}#needs-you" wire:navigate>What does this mean?</a>
                @elseif ($run->status === \App\Enums\RunStatus::Failed)
                    <a class="doc-link" href="{{ route('docs', 'troubleshooting') }}#a-run-failed" wire:navigate>What does this mean?</a>
                @endif
            </p>
        </div>

        <div class="line-controls">
            @if ($run->issue_url)
                <a class="btn" href="{{ $run->issue_url }}" target="_blank" rel="noopener">Open issue</a>
            @endif
            @if ($run->pr_url)
                <a class="btn btn-go" href="{{ $run->pr_url }}" target="_blank" rel="noopener">Open PR</a>
            @endif
            @if ($canFixConflicts)
                <button class="btn btn-go" wire:click="fixConflicts" wire:confirm="The PR conflicts with {{ $run->repository->base_branch }}. Resume the agent to merge {{ $run->repository->base_branch }} and resolve the conflicts? This spends tokens.">Fix conflicts</button>
            @endif
            @if ($canContinue && $run->askedQuestion())
                <button class="btn btn-go" wire:click="continueRun" @unless ($run->hasOperatorReply()) wire:confirm="You haven’t answered yet. Continue anyway? The agent resumes with no answer from you." @endunless>Continue</button>
            @elseif ($canContinue)
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

    @if ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url && $run->stop_reason === 'someone_pushed')
        <div class="notice">
            <p>Someone else pushed to the PR’s branch, so Foreman stopped watching to avoid fighting over it. It’s yours from here.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url && $run->stop_reason === 'merge_conflict')
        <div class="notice">
            <p>The PR conflicts with <code>{{ $run->repository->base_branch }}</code>. Choose <strong>Fix conflicts</strong> to have the agent merge <code>{{ $run->repository->base_branch }}</code> and resolve them, or fix it yourself.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url && $run->stop_reason === 'conflict_fix_failed')
        <div class="notice">
            <p>The agent couldn’t resolve the conflicts, so nothing was pushed. Its explanation is in the thread below. It’s yours from here.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url && in_array($run->stop_reason, ['tier_rose', 'human_review', 'merge_failed'], true))
        <div class="notice">
            <p>Foreman didn’t auto-merge this PR. The reason is in the latest comment on the PR. It’s yours from here.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->pr_url)
        <div class="notice">
            <p>The PR still has failing checks or open review threads that the agent couldn’t settle. It’s yours from here: see the latest comment on the PR.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->stop_reason === 'gate_failed')
        <div class="notice">
            <p>Foreman’s own checks failed before the push, so nothing was pushed. The output is in the comment on the issue. Reply there if the agent should change something, then choose <strong>Continue after my reply</strong> to resume with everything the agent already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->stop_reason === 'scope_vetoed')
        <div class="notice">
            <p>Foreman’s scope judge vetoed the change twice, so nothing was pushed. Its reason is in the comment on the issue. Reply there, then choose <strong>Continue after my reply</strong> to resume with everything the agent already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->stop_reason === 'scope_unusable')
        <div class="notice">
            <p>Foreman’s scope judge gave no usable answer twice, so nothing was pushed. The details are in the comment on the issue. Choose <strong>Continue after my reply</strong> to resume with everything the agent already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @elseif ($run->askedQuestion())
        <div class="notice">
            <p>The agent has a question. Answer it in the thread below, then choose <strong>Continue</strong> to resume with everything it already knows. Or choose <strong>Start over</strong> for a fresh run, which also gets the questions and your answers.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman && $run->stop_reason === 'already_done')
        <div class="notice">
            <p>The agent says the issue is already done, so nothing was pushed. Its explanation is in the comment on the issue. Reply there if you disagree, then choose <strong>Continue after my reply</strong> to resume with everything it already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @elseif ($run->status === \App\Enums\RunStatus::NeedsHuman)
        <div class="notice">
            <p>The agent stopped and asked for help on the issue. Reply there, then choose <strong>Continue after my reply</strong> to resume with everything it already knows, or <strong>Start over</strong> for a fresh run.</p>
        </div>
    @endif

    @if ($run->error)
        <div class="notice notice-halt"><p>{{ $run->error }}</p></div>
    @endif

    @if ($thread->isNotEmpty() || $takesMessages)
        <section class="thread" aria-labelledby="thread-title">
            <header class="thread-head">
                <h2 id="thread-title" class="thread-title">Thread</h2>
                <span class="hint">Round {{ $chain->count() }} · ${{ number_format($chain->sum('cost_usd'), 2) }} across {{ trans_choice('{1} 1 run|[2,*] :count runs', $chain->count()) }}</span>
            </header>
            @if ($thread->isNotEmpty())
                <ol class="thread-list">
                    @php($question = 0)
                    @foreach ($thread as $message)
                        @php($human = $message->role === \App\Models\RunMessage::HUMAN)
                        {{-- A failed conflict fix's explanation is an agent message, but not a question --}}
                        @php($asks = ! $human && ! $chain->firstWhere('id', $message->run_id)?->isConflictFix())
                        <li @class(['thread-message', 'is-human' => $human]) wire:key="message-{{ $message->id }}">
                            <span class="thread-who">{{ $human ? 'You' : 'Agent' }}@if ($asks)<span class="thread-num">Question {{ ++$question }}</span>@endif</span>
                            <p class="thread-body">{{ $message->bodyHtml() }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            @if ($takesMessages)
                <form class="thread-form field" wire:submit="postMessage">
                    <label for="message">{{ $run->askedQuestion() ? 'Your answer' : 'A message for the agent' }}</label>
                    <textarea id="message" rows="5" wire:model="message"></textarea>
                    @error('message')<p class="hint">{{ $message }}</p>@enderror
                    <div class="thread-form-foot">
                        <p class="hint">Saved straight away. The agent reads it when you choose Continue or Start over.</p>
                        <button class="btn btn-go" type="submit">Post</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    <dl class="facts">
        <div><dt>Model</dt><dd>{{ $run->model }}</dd></div>
        <div><dt>Effort</dt><dd>{{ $run->effort ?? 'Default' }}</dd></div>
        @if ($run->difficulty_source)
            <div><dt>Difficulty</dt><dd>{{ $run->difficulty ?? 'none' }} ({{ $run->difficulty_source }})</dd></div>
        @endif
        @if ($run->risk_tier)
            <div><dt>Risk tier</dt><dd>{{ $run->risk_tier }}</dd></div>
        @endif
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

    <div class="log" x-data="runLog(@js($run->status->isActive()))">
        @forelse ($log as $entry)
            <div class="log-entry log-{{ $entry['kind'] }}"><span class="log-time">{{ $entry['time'] }}</span><span class="log-text">{{ $entry['text'] }}</span>@if ($entry['more'] !== '')<span class="log-more">{{ $entry['more'] }}</span>@endif</div>
        @empty
            <span class="log-time">Waiting for the first log line…</span>
        @endforelse
    </div>
</div>
