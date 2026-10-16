<x-admin.layout :title="'Payment '.$payment->reference" :breadcrumbs="['Payments' => route('admin.payments.index'), $payment->reference => null]">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-400">Reference</dt><dd class="mt-1 font-mono text-xs break-all text-white">{{ $payment->reference }}</dd></div>
            <div><dt class="text-ink-400">Status</dt><dd class="mt-1">@include('account.deposits.status-badge', ['status' => $payment->status])</dd></div>
            <div><dt class="text-ink-400">Amount</dt><dd class="mt-1 font-semibold text-white">{{ \App\Support\Money::format($payment->amount_minor, $payment->currency) }}</dd></div>
            <div><dt class="text-ink-400">Player</dt><dd class="mt-1 text-white">
                @can('users.view')<a href="{{ route('admin.users.show', $payment->user_id) }}" class="text-accent-300 hover:text-accent-200">{{ $payment->user?->display_name }}</a>@else{{ $payment->user?->display_name }}@endcan
                <p class="text-xs text-ink-400">{{ $payment->user?->email }}</p></dd></div>
            <div><dt class="text-ink-400">Type</dt><dd class="mt-1 text-white">{{ ucfirst($payment->type->value) }}</dd></div>
            <div><dt class="text-ink-400">Provider</dt><dd class="mt-1 text-white">{{ $payment->gateway }}<p class="font-mono text-xs text-ink-400">{{ $payment->gateway_reference ?? 'no provider reference' }}</p></dd></div>
            <div><dt class="text-ink-400">Created</dt><dd class="mt-1 text-white"><x-datetime :value="$payment->created_at" format="j M Y, g:i:s A" /></dd></div>
            <div><dt class="text-ink-400">Expires</dt><dd class="mt-1 text-white">@if ($payment->expires_at)<x-datetime :value="$payment->expires_at" format="j M Y, g:i:s A" />@else — @endif</dd></div>
            <div><dt class="text-ink-400">Verified by provider</dt><dd class="mt-1 text-white">@if ($payment->verified_at)<x-datetime :value="$payment->verified_at" format="j M Y, g:i:s A" />@else — @endif</dd></div>
            <div><dt class="text-ink-400">Provider status</dt><dd class="mt-1 text-white">{{ $payment->provider_status ?? '—' }}</dd></div>
            <div><dt class="text-ink-400">Failure</dt><dd class="mt-1 text-white">{{ $payment->failure_code ?? '—' }}@if ($payment->failure_message)<p class="text-xs text-ink-400">{{ $payment->failure_message }}</p>@endif</dd></div>
            <div><dt class="text-ink-400">Ledger transaction</dt><dd class="mt-1">
                @if ($payment->ledgerTransaction)<a href="{{ route('admin.ledger.show', $payment->ledgerTransaction) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $payment->ledgerTransaction->reference }}</a>@else <span class="text-ink-400">Not credited</span> @endif
            </dd></div>
        </dl>
    </x-card>

    <x-admin.table caption="Provider notifications (sanitized)" :columns="['Received', 'Outcome', 'Reason', 'Signature', 'From']" :empty="$callbacks->isEmpty()" empty-title="No notifications received">
        @foreach ($callbacks as $callback)
            <tr>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$callback->created_at" format="j M Y, g:i:s A" /></td>
                <td><x-badge :variant="match ($callback->outcome) { 'applied' => 'accent', 'rejected' => 'danger', 'duplicate' => 'neutral', default => 'info' }">{{ ucfirst($callback->outcome) }}</x-badge></td>
                <td class="text-ink-300">{{ $callback->reason ?? '—' }}</td>
                <td class="text-ink-300">{{ $callback->signature_valid ? 'Valid' : 'Invalid' }}</td>
                <td class="font-mono text-xs text-ink-400">{{ $callback->ip_address }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <p class="text-sm text-ink-400">Staff cannot change a payment's status. Unfinished deposits are re-checked with the provider every five minutes by <code>payments:reconcile</code>.</p>
</x-admin.layout>
