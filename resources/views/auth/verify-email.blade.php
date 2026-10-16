<x-auth-card title="Verify your email" subtitle="We sent a verification link to {{ auth()->user()->email }}. Open it to finish setting up your account.">
    <p class="text-sm text-ink-300">You can browse and manage your account now, but you need a verified email before joining competitions.</p>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('verification.send') }}" data-loading>
            @csrf
            <x-button type="submit" data-loading-text="Sending…">Resend verification email</x-button>
        </form>
        <x-button :href="route('account.show')" variant="ghost">Go to my account</x-button>
    </div>
</x-auth-card>
