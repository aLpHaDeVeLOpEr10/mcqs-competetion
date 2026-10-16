<x-admin.layout title="Payments" :breadcrumbs="['Payments' => null]">
    <x-alert variant="info">
        Payment records are read-only. A payment becomes successful only when the provider's verified outcome arrives (callback or reconciliation); staff cannot mark payments as paid. Provider payloads and secrets are never displayed.
    </x-alert>

    <x-admin.filters :action="route('admin.payments.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Reference, provider reference, player email" />
        <x-form.select name="type" label="Type" placeholder="Any type" :selected="$filters->get('type')"
            :options="collect(\App\Enums\PaymentType::cases())->mapWithKeys(fn ($t) => [$t->value => ucfirst($t->value)])->all()" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Payment transactions" :columns="['Reference', 'Created', 'Player', 'Type', 'Amount', 'Status', 'Verified', 'Provider']" :empty="$payments->isEmpty()" empty-title="No payment records" empty-text="Payments appear here once a payment provider is configured and deposits are enabled.">
        @foreach ($payments as $payment)
            <tr>
                <td><a href="{{ route('admin.payments.show', $payment) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $payment->reference }}</a></td>
                <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$payment->created_at" format="j M Y, g:i A" /></td>
                <td class="text-white">{{ $payment->user?->display_name }}<p class="text-xs text-ink-400">{{ $payment->user?->email }}</p></td>
                <td class="text-ink-300">{{ ucfirst($payment->type->value) }}</td>
                <td class="font-semibold whitespace-nowrap text-white">{{ \App\Support\Money::format($payment->amount_minor, $payment->currency) }}</td>
                <td>@include('account.deposits.status-badge', ['status' => $payment->status])
                    @if ($payment->failure_code)<p class="mt-1 text-xs text-ink-400">{{ $payment->failure_code }}</p>@endif
                </td>
                <td class="whitespace-nowrap text-ink-300">@if ($payment->verified_at)<x-datetime :value="$payment->verified_at" format="j M Y, g:i A" />@else — @endif</td>
                <td class="text-ink-400">{{ $payment->gateway }}<p class="font-mono text-xs">{{ $payment->gateway_reference ?? '—' }}</p></td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$payments" noun="payments" />
</x-admin.layout>
