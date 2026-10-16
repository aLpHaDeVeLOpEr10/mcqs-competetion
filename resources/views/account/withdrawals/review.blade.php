<x-account-layout title="Review withdrawal">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Pay to</dt><dd class="mt-1 text-white">{{ $destination->maskedLabel() }}<span class="block text-xs text-ink-400">{{ $destination->account_title }}</span></dd></div>
            <div><dt class="text-ink-400">Amount you receive</dt><dd class="mt-1 text-2xl font-bold text-white">{{ \App\Support\Money::format($amount) }}</dd></div>
            <div><dt class="text-ink-400">Fee</dt><dd class="mt-1 text-white">{{ $fee > 0 ? \App\Support\Money::format($fee) : 'No fee' }}</dd></div>
            <div><dt class="text-ink-400">Taken from your wallet</dt><dd class="mt-1 font-semibold text-white">{{ \App\Support\Money::format($amount + $fee) }}</dd></div>
        </dl>
        @if ($amount + $fee > $balance)
            <x-alert variant="warning" class="mt-4">Your current balance ({{ \App\Support\Money::format($balance) }}) is lower than this total.</x-alert>
        @endif
        <p class="mt-4 text-sm text-ink-400">The total is held from your wallet as soon as you confirm. Every withdrawal is reviewed; if it is rejected or cannot be paid, the full total returns to your wallet.</p>

        <form method="POST" action="{{ route('account.withdrawals.store') }}" class="mt-5 space-y-4" data-loading>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="destination_id" value="{{ $destination->id }}">
            <input type="hidden" name="amount" value="{{ \App\Support\Money::toInput($amount) }}">
            <x-form.checkbox name="confirm">I confirm this withdrawal.</x-form.checkbox>
            <div class="flex flex-wrap gap-3">
                <x-button type="submit" data-loading-text="Requesting…">Confirm withdrawal</x-button>
                <x-button :href="route('account.withdrawals.index')" variant="ghost">Back</x-button>
            </div>
        </form>
    </x-card>
</x-account-layout>
