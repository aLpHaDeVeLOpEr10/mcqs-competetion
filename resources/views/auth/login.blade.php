<x-auth-card title="Sign in" subtitle="Welcome back. Sign in to manage your competitions.">
    <form method="POST" action="{{ route('login') }}" class="space-y-5" data-loading novalidate>
        @csrf
        <x-form.input name="email" type="email" label="Email" autocomplete="email" required autofocus />
        <x-form.input name="password" type="password" label="Password" autocomplete="current-password" required />

        <div class="flex items-center justify-between gap-4">
            <x-form.checkbox name="remember">Keep me signed in</x-form.checkbox>
            <a href="{{ route('password.request') }}" class="text-sm text-accent-300 hover:text-accent-200">Forgot password?</a>
        </div>

        <x-button type="submit" class="w-full" data-loading-text="Signing in…">Sign in</x-button>
    </form>

    <x-slot:footer>
        New here? <a href="{{ route('register') }}" class="font-medium text-accent-300 hover:text-accent-200">Create an account</a>
    </x-slot:footer>
</x-auth-card>
