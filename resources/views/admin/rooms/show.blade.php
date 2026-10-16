<x-admin.layout :title="$room->name" :breadcrumbs="['Rooms' => route('admin.rooms.index'), $room->name => null]">
    <div class="flex flex-wrap items-center gap-3">
        <x-status-badge :status="$room->status" />
        <x-badge>{{ $room->type->label() }}</x-badge>
        @if (in_array($room->status, \App\Enums\RoomStatus::publicCases(), true))
            <a href="{{ route('competitions.show', $room) }}" class="text-sm text-accent-300 hover:text-accent-200">View public page</a>
        @endif
    </div>

    @can('rooms.manage')
        <div class="flex flex-wrap items-start gap-3">
            @if (! in_array($room->status, [\App\Enums\RoomStatus::Cancelled, \App\Enums\RoomStatus::Refunded, \App\Enums\RoomStatus::Completed], true))
                <x-button :href="route('admin.rooms.edit', $room)" variant="secondary" size="sm">Edit</x-button>
            @endif
            @if ($room->isDraft())
                <form method="POST" action="{{ route('admin.rooms.publish', $room) }}" data-confirm="Publish this room? Its competition settings and questions will be locked.">
                    @csrf @method('PATCH')
                    <x-button type="submit" size="sm" :disabled="$problems !== []">Publish</x-button>
                </form>
            @endif
        </div>

        @if (session('problems'))
            <x-alert variant="error" title="Publishing was blocked">
                <ul class="list-disc pl-5">@foreach (session('problems') as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            </x-alert>
        @elseif ($room->isDraft() && $problems !== [])
            <x-alert variant="warning" title="Not ready to publish">
                <ul class="list-disc pl-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            </x-alert>
        @elseif ($room->isDraft())
            <x-alert variant="success">This draft passes all publishing checks.</x-alert>
        @endif
    @endcan

    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-400">Entry fee</dt><dd class="mt-1 text-white">{{ \App\Support\Money::fee($room->entry_fee_minor, $room->currency) }}</dd></div>
            <div><dt class="text-ink-400">Seats taken</dt><dd class="mt-1 text-white">{{ $room->participants_count }} / {{ $room->max_participants }} (min {{ $room->min_participants }})</dd></div>
            <div><dt class="text-ink-400">Quiz paper</dt><dd class="mt-1 text-white">{{ $room->quizPaper->title }}</dd></div>
            <div><dt class="text-ink-400">Category</dt><dd class="mt-1 text-white">{{ $room->category?->name ?? 'From paper' }}</dd></div>
            <div><dt class="text-ink-400">Registration</dt><dd class="mt-1 text-white">
                @if ($room->registration_opens_at)opens <x-datetime :value="$room->registration_opens_at" format="j M, g:i A" />; @endif
                @if ($room->registrationDeadline())closes <x-datetime :value="$room->registrationDeadline()" format="j M, g:i A" />@else no deadline @endif
            </dd></div>
            <div><dt class="text-ink-400">Scheduled start</dt><dd class="mt-1 text-white">@if ($room->scheduled_start_at)<x-datetime :value="$room->scheduled_start_at" />@else When full @endif</dd></div>
            @if ($room->starts_at)
                <div><dt class="text-ink-400">Starts at</dt><dd class="mt-1 text-white"><x-datetime :value="$room->starts_at" format="j M Y, g:i:s A" /></dd></div>
            @endif
            <div><dt class="text-ink-400">If under capacity</dt><dd class="mt-1 text-white">{{ $room->under_capacity_policy->label() }}</dd></div>
            <div><dt class="text-ink-400">Tie-break</dt><dd class="mt-1 text-white">{{ $room->tie_break_policy->label() }}</dd></div>
            <div><dt class="text-ink-400">Withdrawals</dt><dd class="mt-1 text-white">{{ $room->allow_withdrawal ? 'Allowed until registration closes' : 'Not allowed' }}</dd></div>
            <div><dt class="text-ink-400">Snapshot</dt><dd class="mt-1 text-white">{{ $room->session ? 'Frozen (session #'.$room->session->id.', '.str_replace('_', ' ', $room->session->status->value).')' : 'Taken when published' }}</dd></div>
            @if ($room->cancelled_at)
                <div class="sm:col-span-3"><dt class="text-ink-400">Cancelled</dt><dd class="mt-1 text-white"><x-datetime :value="$room->cancelled_at" /> — {{ $room->cancellation_reason }}</dd></div>
            @endif
        </dl>
    </x-card>

    <section aria-labelledby="tiers-heading">
        <h2 id="tiers-heading" class="text-lg font-semibold text-white">Prize tiers</h2>
        <x-admin.table class="mt-3" caption="Prize tiers" :columns="['Ranks', 'Each', 'Tier total']" :empty="$room->prizeTiers->isEmpty()" empty-title="No prizes configured">
            @foreach ($room->prizeTiers as $tier)
                <tr>
                    <td class="text-white">#{{ $tier->rank_from }}{{ $tier->rank_to !== $tier->rank_from ? '–'.$tier->rank_to : '' }}</td>
                    @if ($room->prize_mode === \App\Enums\PrizeMode::Percentage)
                        <td class="text-ink-300">{{ $tier->percentLabel() }} of the pool</td>
                        <td class="text-ink-300">{{ rtrim(rtrim(number_format(($tier->percent_bp ?? 0) * $tier->positions() / 100, 2), '0'), '.') }}%</td>
                    @else
                        <td class="text-ink-300">{{ \App\Support\Money::format($tier->amount_minor, $room->currency) }}</td>
                        <td class="text-ink-300">{{ \App\Support\Money::format($tier->totalAmountMinor(), $room->currency) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-admin.table>
    </section>

    <section aria-labelledby="participants-heading">
        <h2 id="participants-heading" class="text-lg font-semibold text-white">Participants</h2>
        {{-- Read-only: seat rows and their ledger records are financial history and are never deleted. --}}
        <x-admin.table class="mt-3" caption="Participants" :columns="['Player', 'Seat status', 'Charged', 'Entry transaction', 'Joined', 'Refund']" :empty="$participants->isEmpty()" empty-title="No participants yet">
            @foreach ($participants as $participant)
                @php($canSeeLedger = auth()->user()->can('finance.view'))
                <tr>
                    <td class="text-white">{{ $participant->user?->display_name ?? 'Player #'.$participant->user_id }}</td>
                    <td class="text-ink-300">{{ ucfirst($participant->status->value) }}
                        @if ($participant->reserved_until)<p class="text-xs text-ink-400">held until <x-datetime :value="$participant->reserved_until" format="g:i A" /></p>@endif
                    </td>
                    <td class="whitespace-nowrap text-ink-300">{{ $participant->entryLedgerTransaction ? \App\Support\Money::format($participant->entry_fee_minor, $room->currency) : 'Free' }}</td>
                    <td>
                        @if ($participant->entryLedgerTransaction)
                            @if ($canSeeLedger)<a href="{{ route('admin.ledger.show', $participant->entry_ledger_transaction_id) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $participant->entryLedgerTransaction->reference }}</a>@else<span class="font-mono text-xs text-ink-300">{{ $participant->entryLedgerTransaction->reference }}</span>@endif
                        @else <span class="text-ink-400">—</span> @endif
                    </td>
                    <td class="whitespace-nowrap text-ink-400"><x-datetime :value="$participant->joined_at" format="j M Y, g:i A" /></td>
                    <td class="whitespace-nowrap">
                        @if ($participant->refundLedgerTransaction)
                            <span class="text-emerald-300">Refunded</span> <x-datetime :value="$participant->refunded_at" format="j M, g:i A" class="text-xs text-ink-400" />
                            <p>@if ($canSeeLedger)<a href="{{ route('admin.ledger.show', $participant->refund_ledger_transaction_id) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $participant->refundLedgerTransaction->reference }}</a>@else<span class="font-mono text-xs text-ink-300">{{ $participant->refundLedgerTransaction->reference }}</span>@endif</p>
                        @elseif ($participant->entryLedgerTransaction)
                            <span class="text-ink-300">Not refunded</span>
                        @else <span class="text-ink-400">—</span> @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>
        <x-pagination :paginator="$participants" noun="participants" />
    </section>

    @can('rooms.manage')
        @if ($room->status->canTransitionTo(\App\Enums\RoomStatus::Cancelled))
            <x-admin.confirm-action title="Cancel this competition" :action="route('admin.rooms.cancel', $room)" method="PATCH" bag="cancel" submit-label="Cancel competition" danger>
                <p class="text-xs text-ink-400">Every seat is released and any entry fee refunded. Seat history is kept. This cannot be undone.</p>
            </x-admin.confirm-action>
        @endif
    @endcan
</x-admin.layout>
