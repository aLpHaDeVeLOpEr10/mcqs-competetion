<x-auth-card title="Reset your password" subtitle="Enter your account email and we will send you a link to choose a new password.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" data-loading novalidate>
        @csrf
        <x-form.input name="email" type="email" label="Email" autocomplete="email" required autofocus />
        <x-button type="submit" class="w-full" data-loading-text="Sending…">Email reset link</x-button>
    </form>

    <x-slot:footer>
        Remembered it? <a href="{{ route('login') }}" class="font-medium text-accent-300 hover:text-accent-200">Back to sign in</a>
    </x-slot:footer>
</x-auth-card>
