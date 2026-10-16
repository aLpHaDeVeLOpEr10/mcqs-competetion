@props(['title', 'breadcrumbs' => []])

@php
    // Navigation is filtered for convenience only; every route enforces its own permission.
    $sections = [
        'Overview' => [
            ['admin.dashboard', 'admin.dashboard', 'Dashboard', 'admin.access'],
        ],
        'People' => [
            ['admin.users.index', 'admin.users.*', 'Users & roles', 'users.view'],
        ],
        'Content' => [
            ['admin.categories.index', 'admin.categories.*', 'Categories', 'content.view'],
            ['admin.questions.index', 'admin.questions.*', 'Questions', 'content.view'],
            ['admin.papers.index', 'admin.papers.*', 'Quiz papers', 'content.view'],
        ],
        'Competitions' => [
            ['admin.rooms.index', 'admin.rooms.*', 'Rooms', 'rooms.view'],
            ['admin.results.index', 'admin.results.*', 'Results & disputes', 'results.view'],
        ],
        'Finance' => [
            ['admin.payments.index', 'admin.payments.*', 'Payments', 'finance.view'],
            ['admin.wallets.index', 'admin.wallets.*', 'Wallets', 'finance.view'],
            ['admin.reports.index', 'admin.reports.*', 'Reports', 'reports.view'],
            ['admin.exports.index', 'admin.exports.*', 'Exports', 'reports.export'],
            ['admin.withdrawals.index', 'admin.withdrawals.*', 'Withdrawals', 'withdrawals.review'],
            ['admin.payouts.index', 'admin.payouts.*', 'Prize payouts', 'finance.view'],
            ['admin.announcements.index', 'admin.announcements.*', 'Announcements', 'announcements.manage'],
            ['admin.ledger.index', 'admin.ledger.*', 'Wallet ledger', 'finance.view'],
        ],
        'System' => [
            ['admin.risk.index', 'admin.risk.*', 'Risk review', 'risk.review'],
            ['admin.audit.index', 'admin.audit.*', 'Audit log', 'audit.view'],
            ['admin.settings', 'admin.settings', 'Settings', 'settings.view'],
        ],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Admin · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full font-sans">
    <a href="#main" class="sr-only z-50 rounded-lg bg-accent-400 px-4 py-2 font-semibold text-ink-950 focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Skip to content</a>

    <div class="lg:grid lg:min-h-screen lg:grid-cols-[16rem_1fr]">
        <aside class="border-b border-ink-800 bg-ink-900 lg:border-r lg:border-b-0">
            <div class="flex h-16 items-center justify-between gap-3 px-4 lg:px-5">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 font-bold text-white">
                    <span class="grid size-8 place-items-center rounded-lg bg-accent-400 text-ink-950" aria-hidden="true">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 3 4 7v5c0 5 3.5 8 8 9 4.5-1 8-4 8-9V7l-8-4Z" /></svg>
                    </span>
                    Admin
                </a>
                <x-badge variant="info">{{ auth()->user()->role->label() }}</x-badge>
            </div>

            <details class="lg:hidden" data-mobile-menu>
                <summary class="mx-4 mb-3 rounded-lg border border-ink-700 px-3 py-2 text-sm text-ink-300">Menu</summary>
                @include('admin.partials.nav', ['sections' => $sections])
            </details>
            <div class="hidden lg:block">
                @include('admin.partials.nav', ['sections' => $sections])
            </div>
        </aside>

        <div class="min-w-0">
            <header class="flex h-16 items-center justify-between gap-4 border-b border-ink-800 px-4 sm:px-6 lg:px-8">
                <nav aria-label="Breadcrumb" class="min-w-0 truncate text-sm text-ink-400">
                    <a href="{{ route('admin.dashboard') }}" class="hover:text-white">Admin</a>
                    @foreach ($breadcrumbs as $label => $url)
                        <span aria-hidden="true">/</span>
                        @if ($url)
                            <a href="{{ $url }}" class="hover:text-white">{{ $label }}</a>
                        @else
                            <span class="text-ink-300" aria-current="page">{{ $label }}</span>
                        @endif
                    @endforeach
                </nav>
                <div class="flex shrink-0 items-center gap-2">
                    <x-button :href="route('home')" variant="ghost" size="sm">View site</x-button>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm">Sign out</x-button>
                    </form>
                </div>
            </header>

            <main id="main" tabindex="-1" class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <h1 class="text-2xl font-bold text-white">{{ $title }}</h1>

                @if (session('success'))
                    <x-alert variant="success">{{ session('success') }}</x-alert>
                @endif
                @if (session('error'))
                    <x-alert variant="error">{{ session('error') }}</x-alert>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
