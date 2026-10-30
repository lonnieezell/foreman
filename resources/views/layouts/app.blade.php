<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Factory' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=barlow-semi-condensed:500,600,700|public-sans:400,500,600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="masthead">
        <div class="frame masthead-inner">
            <a href="{{ route('dashboard') }}" wire:navigate class="wordmark">Factory</a>
            <nav class="masthead-nav">
                <a href="{{ route('dashboard') }}" wire:navigate @class(['is-current' => request()->routeIs('dashboard')])>Lines</a>
                <a href="{{ route('repositories.create') }}" wire:navigate @class(['is-current' => request()->routeIs('repositories.create')])>Add repository</a>
            </nav>
        </div>
    </header>

    <main class="frame">
        {{ $slot }}
    </main>
</body>
</html>
