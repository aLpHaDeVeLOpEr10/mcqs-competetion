<x-admin.layout title="Audit log" :breadcrumbs="['Audit log' => null]">
    <x-alert variant="info">Audit records are append-only. They cannot be edited or deleted from the application or directly in the database.</x-alert>

    <x-admin.filters :action="route('admin.audit.index')" :filters="$filters">
        <x-form.select name="action" label="Action" placeholder="Any action" :selected="$filters->get('action')" :options="$actions->mapWithKeys(fn ($a) => [$a => $a])->all()" />
        <x-form.input name="actor" type="number" label="Actor user ID" :value="$filters->get('actor')" min="1" />
        <x-form.input name="from" type="date" label="From" :value="$filters->get('from')" />
        <x-form.input name="to" type="date" label="To" :value="$filters->get('to')" />
    </x-admin.filters>

    <x-admin.table caption="Audit records" :columns="['When', 'Action', 'Actor', 'Subject']" :empty="$logs->isEmpty()" empty-title="No audit records match">
        @foreach ($logs as $log)
            @include('admin.audit.row', ['log' => $log])
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$logs" noun="records" />
</x-admin.layout>
