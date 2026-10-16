<x-admin.layout title="Import preview" :breadcrumbs="['Questions' => route('admin.questions.index'), 'Import' => route('admin.questions.import'), 'Preview' => null]">
    <p class="text-sm text-ink-400">File: <span class="text-ink-100">{{ $preview['file_name'] }}</span>. Nothing has been saved yet.</p>

    @if ($preview['file_errors'] !== [])
        <x-alert variant="error" title="The file cannot be imported">
            <ul class="list-disc pl-5">@foreach ($preview['file_errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-alert>
        <x-button :href="route('admin.questions.import')" variant="secondary">Upload another file</x-button>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <x-admin.stat label="Ready to import" :value="$preview['counts']['valid']" />
            <x-admin.stat label="Rows with errors" :value="$preview['counts']['invalid']" hint="Skipped" />
            <x-admin.stat label="Duplicates" :value="$preview['counts']['duplicate']" hint="Skipped" />
        </div>

        <div class="flex flex-wrap gap-3">
            @if ($preview['counts']['valid'] > 0)
                <form method="POST" action="{{ route('admin.questions.import.commit', $preview['token']) }}" data-loading>
                    @csrf
                    <x-button type="submit" data-loading-text="Importing…">Import {{ $preview['counts']['valid'] }} question(s)</x-button>
                </form>
            @endif
            <x-button :href="route('admin.questions.import')" variant="ghost">Cancel and upload another file</x-button>
        </div>

        <x-admin.table caption="Rows in the file" :columns="['Line', 'Result', 'Category', 'Question', 'Options', 'Correct', 'Problems']">
            @foreach ($preview['rows'] as $row)
                <tr>
                    <td class="text-ink-400">{{ $row['line'] }}</td>
                    <td>
                        <x-badge :variant="match ($row['status']) { 'valid' => 'accent', 'duplicate' => 'warning', default => 'danger' }">
                            {{ match ($row['status']) { 'valid' => 'Ready', 'duplicate' => 'Duplicate', default => 'Error' } }}
                        </x-badge>
                    </td>
                    <td class="text-ink-300">{{ \Illuminate\Support\Str::limit($row['display']['category'], 30) }}</td>
                    <td class="max-w-sm text-ink-100">{{ $row['display']['question'] }}</td>
                    <td class="text-ink-300">{{ $row['display']['options'] }}</td>
                    <td class="text-ink-300">{{ \Illuminate\Support\Str::limit($row['display']['correct'], 5) }}</td>
                    <td class="text-xs text-rose-300">
                        @foreach ($row['errors'] as $error)<p>{{ $error }}</p>@endforeach
                    </td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif
</x-admin.layout>
