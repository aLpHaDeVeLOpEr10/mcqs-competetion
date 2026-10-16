<x-account-layout title="Notification preferences">
    <p class="text-sm text-ink-400">Choose how we tell you about things. Security notices, and the in-app record of money in and out of your wallet, are always sent.</p>

    <form method="POST" action="{{ route('account.notifications.preferences.update') }}" data-loading>
        @csrf
        @method('PUT')
        <x-card class="overflow-x-auto" padding="p-0">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Notification channels per topic</caption>
                <thead class="border-b border-ink-700 text-ink-400">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Topic</th>
                        <th scope="col" class="px-4 py-3 text-center font-medium">In the app</th>
                        <th scope="col" class="px-4 py-3 text-center font-medium">Email</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @foreach ($categories as $category)
                        <tr>
                            <th scope="row" class="px-4 py-3 font-normal text-ink-100">{{ $category->label() }}</th>
                            @foreach (['database', 'mail'] as $channel)
                                @php($cell = $matrix[$category->value][$channel])
                                <td class="px-4 py-3 text-center">
                                    <input type="hidden" name="preferences[{{ $category->value }}][{{ $channel }}]" value="0" @disabled($cell['locked'])>
                                    <input type="checkbox" name="preferences[{{ $category->value }}][{{ $channel }}]" value="1" class="size-4 accent-accent-400"
                                        aria-label="{{ $category->label() }}: {{ $channel === 'database' ? 'in the app' : 'email' }}"
                                        @checked($cell['enabled']) @disabled($cell['locked'])>
                                    @if ($cell['locked'])<span class="sr-only">(always on)</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
        <p class="mt-3 text-xs text-ink-400">Greyed-out choices are always on. Emails are sent only to a verified address.</p>
        <x-button type="submit" class="mt-4" data-loading-text="Saving…">Save preferences</x-button>
    </form>
</x-account-layout>
