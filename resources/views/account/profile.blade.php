<x-account-layout title="Profile">
    <x-card as="section" aria-labelledby="profile-heading">
        <h2 id="profile-heading" class="text-lg font-semibold text-white">Profile details</h2>
        <form method="POST" action="{{ route('account.profile.update') }}" class="mt-5 space-y-5" data-loading novalidate>
            @csrf
            @method('PATCH')
            <x-form.input name="name" label="Full name" autocomplete="name" maxlength="100" required :value="$user->name" help="Private. Never shown to other players." />
            <x-form.input name="display_name" label="Display name" autocomplete="username" maxlength="30" required :value="$user->display_name" help="Shown on leaderboards and results." />
            <x-button type="submit" data-loading-text="Saving…">Save profile</x-button>
        </form>
    </x-card>

    <x-card as="section" aria-labelledby="email-heading">
        <h2 id="email-heading" class="text-lg font-semibold text-white">Email address</h2>
        <p class="mt-1 text-sm text-ink-400">
            Current: <span class="text-ink-100">{{ $user->email }}</span>
            @if ($user->hasVerifiedEmail())
                <x-badge variant="accent" class="ml-1">Verified</x-badge>
            @else
                <x-badge variant="warning" class="ml-1">Not verified</x-badge>
            @endif
        </p>

        @if ($user->pending_email)
            <x-alert variant="info" class="mt-4">
                A confirmation link was sent to <strong>{{ $user->pending_email }}</strong>. Your email changes once you open it.
                <form method="POST" action="{{ route('account.email.cancel') }}" class="mt-2">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="font-semibold underline underline-offset-2">Cancel this change</button>
                </form>
            </x-alert>
        @endif

        <form method="POST" action="{{ route('account.email.update') }}" class="mt-5 space-y-5" data-loading novalidate>
            @csrf
            <x-form.input name="email" type="email" label="New email address" autocomplete="email" maxlength="255" required bag="email" />
            <x-form.input name="current_password" type="password" label="Current password" autocomplete="current-password" required bag="email" />
            <x-button type="submit" variant="secondary" data-loading-text="Sending…">Send confirmation link</x-button>
        </form>
    </x-card>
</x-account-layout>
