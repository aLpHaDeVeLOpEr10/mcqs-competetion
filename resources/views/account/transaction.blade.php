<x-account-layout title="Transaction">
    <x-card>
        <p class="text-sm text-ink-400">{{ $transaction->type->label() }}</p>
        <p @class(['mt-1 text-3xl font-bold', 'text-emerald-300' => $entry->amount_minor > 0, 'text-white' => $entry->amount_minor < 0])>
            {{ $entry->amount_minor > 0 ? '+' : '' }}{{ \App\Support\Money::format($entry->amount_minor, $transaction->currency) }}
        </p>
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Reference</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $transaction->reference }}</dd></div>
            <div><dt class="text-ink-400">Date</dt><dd class="mt-1 text-white"><x-datetime :value="$transaction->created_at" format="j M Y, g:i:s A" /></dd></div>
            <div><dt class="text-ink-400">Status</dt><dd class="mt-1">
                @if ($transaction->status() === 'reversed')
                    <x-badge variant="warning">Reversed</x-badge>
                @else
                    <x-badge variant="accent">Completed</x-badge>
                @endif
            </dd></div>
            <div><dt class="text-ink-400">Balance after</dt><dd class="mt-1 text-white">{{ \App\Support\Money::format($entry->balance_after_minor, $transaction->currency) }}</dd></div>
            @if ($transaction->description)
                <div class="sm:col-span-2"><dt class="text-ink-400">Description</dt><dd class="mt-1 text-white">{{ $transaction->description }}</dd></div>
            @endif
            @if ($room)
                <div><dt class="text-ink-400">Competition</dt><dd class="mt-1"><a href="{{ route('competitions.show', $room) }}" class="text-accent-300 hover:text-accent-200">{{ $room->name }}</a></dd></div>
            @endif
            @if ($transaction->reversedTransaction)
                <div><dt class="text-ink-400">Reverses</dt><dd class="mt-1"><a href="{{ route('account.transactions.show', $transaction->reversedTransaction->reference) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $transaction->reversedTransaction->reference }}</a></dd></div>
            @endif
        </dl>
    </x-card>
    <p class="text-sm"><a href="{{ route('account.transactions') }}" class="text-accent-300 hover:text-accent-200">← All transactions</a></p>
    <p class="text-xs text-ink-400">Quote the reference if you contact support about this transaction.</p>
</x-account-layout>
