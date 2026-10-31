<div class="docs">
    <nav class="docs-nav" aria-label="Docs">
        @foreach (\App\Livewire\Docs::PAGES as $slug => $title)
            <a href="{{ route('docs', $slug) }}" wire:navigate @class(['is-current' => $slug === $page])>{{ $title }}</a>
        @endforeach
    </nav>

    <article class="doc-prose">{!! $html !!}</article>
</div>
