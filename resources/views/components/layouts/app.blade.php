@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? 'Live, timed multiple-choice competitions with transparent scoring.' }}">
    <meta name="theme-color" content="#070b14">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @auth
        @if (config('competition.notifications.broadcast') && config('broadcasting.default') !== 'null')
            @vite('resources/js/notifications.js')
        @endif
    @endauth
    @stack('scripts')
</head>
<body class="flex min-h-full flex-col font-sans">
    <a href="#main" class="sr-only z-50 rounded-lg bg-accent-400 px-4 py-2 font-semibold text-ink-950 focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Skip to content</a>

    @include('partials.header')

    <main id="main" class="flex-1" tabindex="-1">
        @include('partials.flash')
        {{ $slot }}
    </main>

    @include('partials.footer')
</body>
</html>
