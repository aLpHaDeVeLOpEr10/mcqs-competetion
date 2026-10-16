<x-admin.layout :title="'Audit record #'.$log->id" :breadcrumbs="['Audit log' => route('admin.audit.index'), '#'.$log->id => null]">
    <x-card>
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-400">Action</dt><dd class="mt-1 font-mono text-white">{{ $log->action }}</dd></div>
            <div><dt class="text-ink-400">When</dt><dd class="mt-1 text-white"><x-datetime :value="$log->created_at" format="j M Y, g:i:s A" /></dd></div>
            <div><dt class="text-ink-400">Actor</dt><dd class="mt-1 text-white">{{ $log->actor ? $log->actor->name.' (#'.$log->actor->id.')' : 'System' }}</dd></div>
            <div><dt class="text-ink-400">Subject</dt><dd class="mt-1 text-white">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</dd></div>
            <div><dt class="text-ink-400">IP address</dt><dd class="mt-1 text-white">{{ $log->ip_address ?? '—' }}</dd></div>
            <div><dt class="text-ink-400">Request id</dt><dd class="mt-1 font-mono text-xs break-all text-white">
                @if ($log->request_id)<a href="{{ route('admin.audit.index', ['request' => $log->request_id]) }}" class="text-accent-300 hover:text-accent-200" title="All records of this request">{{ $log->request_id }}</a>@else — @endif
            </dd></div>
            <div><dt class="text-ink-400">User agent</dt><dd class="mt-1 text-xs break-all text-ink-300">{{ $log->user_agent ?? '—' }}</dd></div>
        </dl>
    </x-card>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach (['Before' => $log->old_values, 'After' => $log->new_values, 'Details' => $log->metadata] as $label => $values)
            <x-card>
                <h2 class="text-sm font-semibold text-ink-100">{{ $label }}</h2>
                @if ($values)
                    <pre class="mt-2 overflow-x-auto text-xs text-ink-300">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @else
                    <p class="mt-2 text-xs text-ink-400">—</p>
                @endif
            </x-card>
        @endforeach
    </div>
</x-admin.layout>
