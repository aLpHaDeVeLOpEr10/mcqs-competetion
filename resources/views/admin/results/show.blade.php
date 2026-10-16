<x-admin.layout :title="'Results: '.$session->room->name" :breadcrumbs="['Results' => route('admin.results.index'), $session->room->name => null]">
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <x-badge :variant="$session->status === \App\Enums\SessionStatus::Completed ? 'accent' : 'warning'">{{ ucfirst(str_replace('_', ' ', $session->status->value)) }}</x-badge>
        <span class="text-ink-400">Policy: {{ $session->config['scoring_policy'] ?? \App\Services\Results\ScoringPolicy::VERSION }} · tie-break {{ $session->config['tie_break_policy'] ?? '—' }}</span>
        @if ($session->results_published_at)
            <span class="text-ink-400">Published <x-datetime :value="$session->results_published_at" format="j M Y, g:i A" /></span>
            <a href="{{ route('competitions.results', $session->room_id) }}" class="text-accent-300 hover:text-accent-200">Public results page</a>
        @endif
    </div>

    @can('results.manage')
        <div class="flex flex-wrap gap-2">
            @if ($session->status === \App\Enums\SessionStatus::Scoring)
                <form method="POST" action="{{ route('admin.results.finalize', $session) }}">@csrf <x-button type="submit" size="sm">Finalize results now</x-button></form>
            @endif
            @if ($session->status === \App\Enums\SessionStatus::Completed && ! $session->results_published_at)
                <form method="POST" action="{{ route('admin.results.publish', $session) }}" data-confirm="Publish these results to players?">@csrf <x-button type="submit" size="sm">Publish results</x-button></form>
            @endif
        </div>
    @endcan

    <section aria-labelledby="ranking-heading">
        <h2 id="ranking-heading" class="text-lg font-semibold text-white">Ranking (current version)</h2>
        <x-admin.table class="mt-3" caption="Ranking" :columns="['Rank', 'Player', 'Correct', 'Answered', 'Correct time', 'Last correct', 'Prize', 'Notes']" :empty="$ranking->isEmpty()" empty-title="No results yet">
            @foreach ($ranking as $r)
                <tr>
                    <td class="font-semibold text-white">{{ $r->rank ? '#'.$r->rank : '—' }}</td>
                    <td class="text-white">{{ $r->participant->user?->display_name }} <span class="text-xs text-ink-400">(seat #{{ $r->room_participant_id }})</span></td>
                    <td class="text-ink-300">{{ $r->correct_count }}</td>
                    <td class="text-ink-300">{{ $r->answered_count }}</td>
                    <td class="text-ink-300">{{ number_format($r->correct_response_time_ms / 1000, 3) }} s</td>
                    <td class="text-ink-300">{{ $r->last_correct_elapsed_ms !== null ? number_format($r->last_correct_elapsed_ms / 1000, 3).' s' : '—' }}</td>
                    <td class="text-ink-300">{{ $r->prize_amount_minor ? \App\Support\Money::format($r->prize_amount_minor, $session->room->currency) : '—' }}</td>
                    <td class="text-xs text-ink-400">{{ $r->excluded_reason ?? ($r->prize_eligible ? '' : 'not prize-eligible') }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    </section>

    @if ($session->prizeDistribution)
        @php($calc = $session->prizeDistribution->calculation)
        <section aria-labelledby="prizes-heading">
            <h2 id="prizes-heading" class="text-lg font-semibold text-white">Prize entitlements</h2>
            <p class="mt-1 text-sm text-ink-400">
                Configured {{ \App\Support\Money::format($calc['configured_total_minor'] ?? 0) }} ·
                entitled {{ \App\Support\Money::format($session->prizeDistribution->total_amount_minor) }} ·
                unallocated {{ \App\Support\Money::format($calc['unallocated_minor'] ?? 0) }} ({{ $calc['rounding'] ?? '' }}).
                @can('finance.view')
                    <a href="{{ route('admin.payouts.show', $session) }}" class="text-accent-300 hover:text-accent-200">Manage payouts</a>
                @endcan
            </p>
            <x-admin.table class="mt-3" caption="Prize entitlements" :columns="['Seat', 'Rank', 'Amount', 'Status', 'Reason']" :empty="$session->prizeDistribution->payouts->isEmpty()" empty-title="No prize entitlements">
                @foreach ($session->prizeDistribution->payouts as $payout)
                    <tr>
                        <td class="text-white">#{{ $payout->room_participant_id }}</td>
                        <td class="text-ink-300">#{{ $payout->rank }}</td>
                        <td class="text-ink-300">{{ \App\Support\Money::format($payout->amount_minor, $session->room->currency) }}</td>
                        <td>@include('admin.payouts.status-badge', ['status' => $payout->status])</td>
                        <td class="text-xs text-ink-400">{{ $payout->status_reason }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>
    @endif

    <section aria-labelledby="versions-heading">
        <h2 id="versions-heading" class="text-lg font-semibold text-white">Result versions (append-only)</h2>
        <x-admin.table class="mt-3" caption="Result versions" :columns="['Version', 'When', 'By', 'Reason', 'Inputs hash']" :empty="$versions->isEmpty()" empty-title="Not finalized yet">
            @foreach ($versions as $v)
                <tr>
                    <td class="text-white">v{{ $v->version }}</td>
                    <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$v->created_at" format="j M Y, g:i:s A" /></td>
                    <td class="text-ink-300">{{ $v->creator?->name ?? 'System' }}</td>
                    <td class="text-ink-300">{{ $v->reason }}</td>
                    <td class="font-mono text-xs text-ink-400">{{ substr($v->inputs_hash, 0, 16) }}…</td>
                </tr>
            @endforeach
        </x-admin.table>
    </section>

    <section aria-labelledby="disputes-heading">
        <h2 id="disputes-heading" class="text-lg font-semibold text-white">Disputes</h2>
        @forelse ($disputes as $d)
            <x-card class="mt-3" padding="p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="text-white">#{{ $d->id }} · {{ $d->raiser?->display_name }} (seat #{{ $d->room_participant_id }})</span>
                    <x-badge :variant="$d->status === \App\Enums\DisputeStatus::Open ? 'warning' : 'neutral'">{{ ucfirst($d->status->value) }}</x-badge>
                </div>
                <p class="mt-2 text-sm text-ink-300">{{ $d->message }}</p>
                @if ($d->resolution)
                    <p class="mt-2 text-xs text-ink-400">Resolution by {{ $d->resolver?->name }}: {{ $d->resolution }}
                        @if ($d->result_version_after) (v{{ $d->result_version_before }} → v{{ $d->result_version_after }}) @endif</p>
                @endif
                @if ($d->status === \App\Enums\DisputeStatus::Open)
                    @can('results.manage')
                        <form method="POST" action="{{ route('admin.disputes.reject', $d) }}" class="mt-3 space-y-2" novalidate>
                            @csrf
                            <x-form.textarea name="resolution" :id="'resolution-'.$d->id" label="Reply (rejects the dispute)" rows="2" bag="dispute" />
                            <x-button type="submit" variant="secondary" size="sm">Reject dispute</x-button>
                        </form>
                        <p class="mt-2 text-xs text-ink-400">To uphold it, apply a correction below and select dispute #{{ $d->id }}.</p>
                    @endcan
                @endif
            </x-card>
        @empty
            <p class="mt-2 text-sm text-ink-400">No disputes.</p>
        @endforelse
    </section>

    @can('results.manage')
        @if ($session->status === \App\Enums\SessionStatus::Completed)
            @php($openDisputes = $disputes->where('status', \App\Enums\DisputeStatus::Open)->mapWithKeys(fn ($d) => [$d->id => '#'.$d->id.' — '.\Illuminate\Support\Str::limit($d->message, 40)])->all())
            <section aria-labelledby="corrections-heading" class="space-y-3">
                <h2 id="corrections-heading" class="text-lg font-semibold text-white">Corrections</h2>
                <p class="text-sm text-ink-400">A correction recalculates every result as a new version. The previous version is kept and the change is audited.</p>
                <x-admin.confirm-action title="Void a question (e.g. wrong answer key)" :action="route('admin.results.void', $session)" bag="void" submit-label="Void and recalculate" danger>
                    <x-form.select name="session_question_id" id="void-question" label="Question" required bag="void" :selected="old('session_question_id')"
                        :options="$questions->whereNull('voided_at')->mapWithKeys(fn ($q) => [$q->id => $q->position.'. '.\Illuminate\Support\Str::limit($q->body, 60)])->all()" />
                    <x-form.select name="dispute_id" id="void-dispute" label="Resolves dispute (optional)" placeholder="None" bag="void" :options="$openDisputes" />
                </x-admin.confirm-action>
                <x-admin.confirm-action title="Disqualify a player" :action="route('admin.results.disqualify', $session)" bag="disqualify" submit-label="Disqualify and recalculate" danger>
                    <x-form.select name="room_participant_id" id="dq-seat" label="Player" required bag="disqualify" :selected="old('room_participant_id')"
                        :options="$ranking->whereNull('excluded_reason')->mapWithKeys(fn ($r) => [$r->room_participant_id => ($r->participant->user?->display_name).' (seat #'.$r->room_participant_id.')'])->all()" />
                    <x-form.select name="dispute_id" id="dq-dispute" label="Resolves dispute (optional)" placeholder="None" bag="disqualify" :options="$openDisputes" />
                </x-admin.confirm-action>
            </section>
        @endif
    @endcan
</x-admin.layout>
