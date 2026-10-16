<x-auth-card title="Create your account" subtitle="Join timed MCQ competitions. It only takes a minute.">
    <form method="POST" action="{{ route('register') }}" class="space-y-5" data-loading novalidate>
        @csrf
        <x-form.input name="name" label="Full name" autocomplete="name" maxlength="100" required autofocus help="Private. Never shown to other players." />
        <x-form.input name="display_name" label="Display name" autocomplete="username" maxlength="30" required help="Shown on leaderboards. 3–30 letters, numbers, dots, dashes or underscores." />
        <x-form.input name="email" type="email" label="Email" autocomplete="email" maxlength="255" required />
        <x-form.input name="password" type="password" label="Password" autocomplete="new-password" required help="At least 10 characters with upper- and lower-case letters and a number." />
        <x-form.input name="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" required />

        <x-form.checkbox name="terms" required>
            I accept the <a href="{{ route('terms') }}" class="text-accent-300 underline" target="_blank" rel="noopener">Terms of use</a>
            and have read the <a href="{{ route('privacy') }}" class="text-accent-300 underline" target="_blank" rel="noopener">Privacy policy</a>.
        </x-form.checkbox>

        <x-button type="submit" class="w-full" data-loading-text="Creating account…">Create account</x-button>
    </form>

    <x-slot:footer>
        Already have an account? <a href="{{ route('login') }}" class="font-medium text-accent-300 hover:text-accent-200">Sign in</a>
    </x-slot:footer>
</x-auth-card>
