<div>
    <div class="run-head">
        <h1>History</h1>
        <select wire:model.live="repositoryId" aria-label="Repository">
            <option value="">All repositories</option>
            @foreach ($repositories as $repo)
                <option value="{{ $repo->id }}">{{ $repo->name }}{{ $repo->archived_at ? ' (archived)' : '' }}</option>
            @endforeach
        </select>
    </div>

    @if ($issues->isEmpty())
        <div class="empty">
            <h2>No history yet</h2>
            <p>Issues Foreman has worked on show up here.</p>
        </div>
    @else
        <table class="history-table">
            <thead>
                <tr>
                    <th>Issue</th><th>Repository</th><th>Title</th><th>Status</th>
                    <th>Runs</th><th>Turns</th><th>Cost</th><th>Tokens in</th><th>Tokens out</th><th>Last changed</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($issues as $issue)
                    @php($run = $latest[$issue->latest_id])
                    <tr wire:key="history-{{ $issue->repository_id }}-{{ $issue->issue_number }}">
                        <td><a href="{{ route('runs.show', $run) }}" wire:navigate>#{{ $issue->issue_number }}</a></td>
                        <td>{{ $run->repository->name }}</td>
                        <td>{{ $run->issue_title }}</td>
                        <td>{{ $run->statusLabel() }}</td>
                        <td>{{ $issue->run_count }}</td>
                        <td>{{ number_format($issue->turns) }}</td>
                        <td>${{ number_format($issue->cost, 2) }}</td>
                        <td title="{{ number_format($issue->tokens_in) }}">{{ Number::abbreviate((int) $issue->tokens_in, maxPrecision: 1) }}</td>
                        <td title="{{ number_format($issue->tokens_out) }}">{{ Number::abbreviate((int) $issue->tokens_out, maxPrecision: 1) }}</td>
                        <td>{{ ($run->finished_at ?? $run->updated_at)->diffForHumans(['short' => true]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $issues->links() }}
    @endif
</div>
