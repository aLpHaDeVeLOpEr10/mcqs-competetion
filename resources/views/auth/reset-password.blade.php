<x-auth-card title="Choose a new password">
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5" data-loading novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form.input name="email" type="email" label="Email" autocomplete="email" required :value="old('email', $email)" />
        <x-form.input name="password" type="password" label="New password" autocomplete="new-password" required autofocus help="At least 10 characters with upper- and lower-case letters and a number." />
        <x-form.input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required />
        <x-button type="submit" class="w-full" data-loading-text="Saving…">Reset password</x-button>
    </form>
</x-auth-card>
