<x-account-layout title="Security">
    <x-card as="section" aria-labelledby="password-heading">
        <h2 id="password-heading" class="text-lg font-semibold text-white">Change password</h2>
        <p class="mt-1 text-sm text-ink-400">Changing your password signs you out everywhere else.</p>
        <form method="POST" action="{{ route('account.password.update') }}" class="mt-5 space-y-5" data-loading novalidate>
            @csrf
            @method('PUT')
            <x-form.input name="current_password" type="password" label="Current password" autocomplete="current-password" required bag="password" />
            <x-form.input name="password" type="password" label="New password" autocomplete="new-password" required bag="password" help="At least 10 characters with upper- and lower-case letters and a number." />
            <x-form.input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required bag="password" />
            <x-button type="submit" data-loading-text="Updating…">Update password</x-button>
        </form>
    </x-card>

    <x-card as="section" aria-labelledby="sessions-heading">
        <h2 id="sessions-heading" class="text-lg font-semibold text-white">Active sessions</h2>
        <p class="mt-1 text-sm text-ink-400">Devices currently signed in to your account. Times are shown in {{ \App\Support\DisplayTime::zoneLabel() }}.</p>

        @if (! $sessionsSupported)
            <x-alert variant="info" class="mt-4">Session listing is not available with the current session configuration.</x-alert>
        @else
            <ul class="mt-4 divide-y divide-ink-800 rounded-xl border border-ink-700">
                @foreach ($sessions as $session)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                        <div>
                            <p class="font-medium text-ink-100">{{ $session->device }}</p>
                            <p class="text-ink-400">{{ $session->ip_address ?? 'Unknown IP' }} · active <x-datetime :value="$session->last_active" format="j M, g:i A" /></p>
                        </div>
                        @if ($session->is_current)
                            <x-badge variant="accent">This device</x-badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('account.sessions.destroy') }}" class="mt-5 space-y-4" data-loading novalidate>
            @csrf
            @method('DELETE')
            <x-form.input name="password" type="password" label="Confirm with your password" autocomplete="current-password" required bag="sessions" />
            <x-button type="submit" variant="secondary" data-loading-text="Signing out…">Sign out other sessions</x-button>
        </form>
    </x-card>
</x-account-layout>
