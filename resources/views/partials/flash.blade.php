@if (session('success') || session('error'))
    <div class="mx-auto max-w-6xl px-4 pt-6 sm:px-6 lg:px-8">
        @if (session('success'))
            <x-alert variant="success">{{ session('success') }}</x-alert>
        @endif
        @if (session('error'))
            <x-alert variant="error">{{ session('error') }}</x-alert>
        @endif
    </div>
@endif
