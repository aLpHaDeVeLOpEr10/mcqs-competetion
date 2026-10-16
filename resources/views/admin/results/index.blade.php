<x-admin.layout title="Results & disputes" :breadcrumbs="['Results' => null]">
    <x-alert variant="info" title="Results are read-only">
        Results are produced by the scoring service and are never edited directly. Corrections recalculate everything as a new, audited version and keep the previous one.
    </x-alert>

    <x-admin.filters :action="route('admin.results.index')" :filters="$filters">
        <x-form.select name="status" label="Session status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\SessionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])->all()" />
        <x-form.select name="published" label="Published" placeholder="Either" :selected="$filters->get('published')" :options="['yes' => 'Published', 'no' => 'Not published']" />
    </x-admin.filters>

    <x-admin.table caption="Competition sessions" :columns="['Room', 'Session status', 'Ranked players', 'Open disputes', 'Results published']" :empty="$sessions->isEmpty()" empty-title="No competition sessions yet" empty-text="Sessions are created when rooms start, which happens in a later phase.">
        @foreach ($sessions as $session)
            <tr>
                <td><a href="{{ route('admin.results.show', $session) }}" class="font-medium text-white hover:text-accent-300">{{ $session->room->name }}</a></td>
                <td class="text-ink-300">{{ ucfirst(str_replace('_', ' ', $session->status->value)) }}</td>
                <td class="text-ink-300">{{ $session->results_count }}</td>
                <td class="text-ink-300">{{ $session->open_disputes_count ?: '—' }}</td>
                <td class="whitespace-nowrap text-ink-300">@if ($session->results_published_at)<x-datetime :value="$session->results_published_at" format="j M Y, g:i A" />@else Not published @endif</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$sessions" noun="sessions" />
</x-admin.layout>
