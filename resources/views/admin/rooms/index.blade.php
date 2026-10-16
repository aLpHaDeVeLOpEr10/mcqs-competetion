<x-admin.layout title="Competition rooms" :breadcrumbs="['Rooms' => null]">
    @can('rooms.manage')
        <x-button :href="route('admin.rooms.create')" size="sm">New room</x-button>
    @endcan

    <x-admin.filters :action="route('admin.rooms.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Room name" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\RoomStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
        <x-form.select name="type" label="Type" placeholder="Any type" :selected="$filters->get('type')"
            :options="collect(\App\Enums\RoomType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Rooms" :columns="['Room', 'Status', 'Entry', 'Seats', 'Starts', 'Paper']" :empty="$rooms->isEmpty()" empty-title="No rooms match">
        @foreach ($rooms as $room)
            <tr>
                <td><a href="{{ route('admin.rooms.show', $room) }}" class="font-medium text-white hover:text-accent-300">{{ $room->name }}</a><p class="text-xs text-ink-400">{{ $room->type->label() }}</p></td>
                <td><x-status-badge :status="$room->status" /></td>
                <td class="text-ink-300">{{ \App\Support\Money::fee($room->entry_fee_minor, $room->currency) }}</td>
                <td class="text-ink-300">{{ $room->participants_count }} / {{ $room->max_participants }}</td>
                <td class="whitespace-nowrap text-ink-300">@if ($room->scheduled_start_at)<x-datetime :value="$room->scheduled_start_at" format="j M Y, g:i A" />@else When full @endif</td>
                <td class="text-ink-400">{{ $room->quizPaper->title }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$rooms" noun="rooms" />
</x-admin.layout>
