<x-admin.layout title="Import questions" :breadcrumbs="['Questions' => route('admin.questions.index'), 'Import' => null]">
    <x-card class="space-y-4">
        <p class="text-sm text-ink-300">
            Upload a CSV file (UTF-8 or Excel “CSV” format, comma or semicolon separated, up to {{ config('competition.questions.import_max_rows') }} rows).
            You will see a preview with every row checked before anything is saved.
        </p>
        <x-button :href="route('admin.questions.import.sample')" variant="secondary" size="sm">Download sample CSV</x-button>

        <form method="POST" action="{{ route('admin.questions.import.store') }}" enctype="multipart/form-data" class="space-y-4" data-loading novalidate>
            @csrf
            <x-form.input name="file" type="file" label="CSV file" accept=".csv,text/csv" required />
            <x-button type="submit" data-loading-text="Checking…">Upload and preview</x-button>
        </form>
    </x-card>

    <x-card as="section" aria-labelledby="format-heading">
        <h2 id="format-heading" class="font-semibold text-white">File format</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div><dt class="inline font-medium text-ink-100">Required columns:</dt> <dd class="inline font-mono text-xs text-ink-300">{{ implode(', ', $required) }}</dd></div>
            <div><dt class="inline font-medium text-ink-100">Optional columns:</dt> <dd class="inline font-mono text-xs text-ink-300">{{ implode(', ', $optional) }}</dd></div>
        </dl>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-ink-400">
            <li><strong class="text-ink-200">category</strong> must match an existing, active category name or slug.</li>
            <li><strong class="text-ink-200">correct</strong> is the letter of the right option (A–F). Fill options in order with no gaps.</li>
            <li><strong class="text-ink-200">difficulty</strong>: easy, medium (default) or hard. <strong class="text-ink-200">status</strong>: draft (default) or active.</li>
            <li><strong class="text-ink-200">time_limit</strong> in seconds ({{ config('competition.questions.min_time_limit') }}–{{ config('competition.questions.max_time_limit') }}), or empty to use the paper default. <strong class="text-ink-200">tags</strong> separated by semicolons.</li>
            <li>Questions already in the bank, or repeated in the file, are flagged as duplicates and skipped.</li>
            <li>Cells starting with =, +, -, @ (other than plain numbers) are rejected as spreadsheet formulas.</li>
            <li>Images cannot be imported; add them by editing the question afterwards.</li>
        </ul>
    </x-card>
</x-admin.layout>
