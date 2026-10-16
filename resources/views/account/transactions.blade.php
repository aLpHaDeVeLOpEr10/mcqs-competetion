<x-account-layout title="Transactions">
    @php
        $types = ['' => 'All', 'deposit' => 'Deposits', 'entry_fee' => 'Entry fees', 'entry_refund' => 'Refunds', 'prize_payout' => 'Prizes', 'withdrawal' => 'Withdrawals', 'adjustment' => 'Adjustments', 'reversal' => 'Reversals'];
        $current = $filters->get('type', '');
    @endphp

    <nav aria-label="Transaction type" class="-mx-1 overflow-x-auto">
        <ul class="flex min-w-max gap-1 px-1 text-sm font-medium">
            @foreach ($types as $value => $label)
                <li>
                    <a href="{{ route('account.transactions', $value === '' ? [] : ['type' => $value]) }}" @if ($current === $value) aria-current="page" @endif
                        @class(['inline-flex rounded-lg px-3 py-2', 'bg-ink-800 text-white' => $current === $value, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => $current !== $value])>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    @if (! $entries || $entries->isEmpty())
        <x-empty-state title="{{ $current === '' ? 'No transactions yet' : 'No '.strtolower($types[$current] ?? 'matching').' yet' }}">
            Deposits, entry fees, refunds and prizes appear here once they happen.
        </x-empty-state>
    @else
        @include('account.partials.ledger-table', ['entries' => $entries])
        <x-pagination :paginator="$entries" noun="transactions" />
    @endif
</x-account-layout>
