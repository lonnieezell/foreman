<div>
    <div class="run-head">
        <h1>Archive</h1>
    </div>

    @forelse ($repositories as $repo)
        <article class="line" wire:key="archived-{{ $repo->id }}">
            <div class="line-head">
                <div>
                    <h2 class="line-name">{{ $repo->name }}</h2>
                    <p class="line-meta">
                        <span>{{ $repo->github_repo }}</span>
                        <span>Archived {{ $repo->archived_at->diffForHumans() }}</span>
                    </p>
                </div>

                <div class="line-controls">
                    <button wire:click="restore({{ $repo->id }})" class="btn">Restore</button>
                    <button wire:click="remove({{ $repo->id }})" wire:confirm="Remove {{ $repo->name }} and its run history from the factory? Nothing on GitHub is touched." class="btn btn-halt">Remove</button>
                </div>
            </div>
        </article>
    @empty
        <div class="empty">
            <h2>Nothing archived</h2>
            <p>Archive a repository from its settings to take it off the dashboard without losing its settings.</p>
        </div>
    @endforelse
</div>
