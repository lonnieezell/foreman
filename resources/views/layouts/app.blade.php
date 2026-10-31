<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Foreman' }}</title>
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#131a22">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=barlow-semi-condensed:500,600,700|public-sans:400,500,600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="masthead">
        <div class="frame masthead-inner">
            <a href="{{ route('dashboard') }}" wire:navigate class="wordmark"><img src="/icon-192.png" alt="" class="wordmark-logo">Foreman</a>
            <nav class="masthead-nav">
                <a href="{{ route('archive') }}" wire:navigate @class(['is-current' => request()->routeIs('archive')])>Archive</a>
                <a href="{{ route('settings') }}" wire:navigate @class(['is-current' => request()->routeIs('settings')])>Settings</a>
            </nav>
        </div>
    </header>

    <main class="frame">
        {{ $slot }}
    </main>
</body>
</html>
