<x-auth-card title="Admin sign in" subtitle="Staff accounts only. This is separate from your player sign-in on the site.">
    @if (session('error'))
        <x-alert variant="error" class="mb-5">{{ session('error') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-5" data-loading novalidate>
        @csrf
        <x-form.input name="email" type="email" label="Email" autocomplete="email" required autofocus />
        <x-form.input name="password" type="password" label="Password" autocomplete="current-password" required />

        <x-button type="submit" class="w-full" data-loading-text="Signing in…">Sign in to admin</x-button>
    </form>

    <x-slot:footer>
        Not staff? <a href="{{ route('login') }}" class="font-medium text-accent-300 hover:text-accent-200">Player sign in</a>
    </x-slot:footer>
</x-auth-card>
