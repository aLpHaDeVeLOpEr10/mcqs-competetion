{{-- The signed-in player's own entry. Amounts come from the seat's frozen fee and its ledger records. --}}
<section aria-labelledby="my-entry-heading" class="mt-6 rounded-xl border border-ink-700 p-4 text-sm">
    <h2 id="my-entry-heading" class="font-semibold text-white">Your entry</h2>
    <dl class="mt-3 space-y-2">
        <div class="flex justify-between gap-3">
            <dt class="text-ink-400">Participation</dt>
            <dd class="text-right text-white">
                @switch($seat->status)
                    @case(\App\Enums\ParticipantStatus::Joined) Confirmed @break
                    @case(\App\Enums\ParticipantStatus::Playing) Playing @break
                    @case(\App\Enums\ParticipantStatus::Finished) Finished @break
                    @case(\App\Enums\ParticipantStatus::Withdrawn) Withdrawn @break
                    @case(\App\Enums\ParticipantStatus::Refunded) Cancelled and refunded @break
                    @default {{ ucfirst($seat->status->value) }}
                @endswitch
            </dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-ink-400">Entry paid</dt>
            <dd class="text-right text-white">
                @if ($seat->entryLedgerTransaction)
                    {{ \App\Support\Money::format($seat->entry_fee_minor, $room->currency) }}
                    <a href="{{ route('account.transactions.show', $seat->entryLedgerTransaction->reference) }}" class="block font-mono text-xs text-accent-300 hover:text-accent-200">{{ $seat->entryLedgerTransaction->reference }}</a>
                @else
                    Free entry
                @endif
            </dd>
        </div>
        @if ($seat->refundLedgerTransaction)
            <div class="flex justify-between gap-3">
                <dt class="text-ink-400">Refunded</dt>
                <dd class="text-right text-emerald-300">
                    {{ \App\Support\Money::format($seat->entry_fee_minor, $room->currency) }}
                    <a href="{{ route('account.transactions.show', $seat->refundLedgerTransaction->reference) }}" class="block font-mono text-xs text-accent-300 hover:text-accent-200">{{ $seat->refundLedgerTransaction->reference }}</a>
                </dd>
            </div>
        @endif
        <div class="flex justify-between gap-3">
            <dt class="text-ink-400">Room status</dt>
            <dd class="text-right text-white">{{ $room->status->label() }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-ink-400">Starts</dt>
            <dd class="text-right text-white">
                @if ($room->starts_at ?? $room->scheduled_start_at)
                    <x-datetime :value="$room->starts_at ?? $room->scheduled_start_at" />
                @else
                    When the room fills
                @endif
            </dd>
        </div>
    </dl>
    <a href="#rules" class="mt-3 inline-flex text-xs font-medium text-accent-300 hover:text-accent-200">Competition rules</a>
</section>
