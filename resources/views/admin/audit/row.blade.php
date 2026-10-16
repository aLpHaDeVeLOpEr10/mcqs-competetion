<tr>
    <td class="whitespace-nowrap text-ink-300"><x-datetime :value="$log->created_at" format="j M Y, g:i:s A" /></td>
    <td><a href="{{ route('admin.audit.show', $log) }}" class="font-mono text-xs text-accent-300 hover:text-accent-200">{{ $log->action }}</a></td>
    <td class="text-ink-100">{{ $log->actor ? $log->actor->name.($log->actor->trashed() ? ' (deleted)' : '') : 'System' }}</td>
    <td class="text-ink-400">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
</tr>
