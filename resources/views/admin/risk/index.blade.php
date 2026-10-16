<x-admin.layout title="Risk review" :breadcrumbs="['Risk review' => null]">
    <x-alert variant="info">
        These are <strong>signals for review, not proof</strong> of fraud or cheating. Nothing here blocks or penalizes anyone.
        Fast answers, reloads, a second device or a shared network are often innocent. Look at the account's history
        before taking any action through the normal, audited tools. {{ $openCount }} open {{ Str::plural('flag', $openCount) }}.
    </x-alert>

    <x-admin.filters :action="route('admin.risk.index')" :filters="$filters">
        <x-form.select name="status" label="Status" :selected="$status" :options="['open' => 'Open', 'watching' => 'Watching (below threshold)', 'reviewed' => 'Reviewed', 'dismissed' => 'Dismissed', 'all' => 'All']" />
        <x-form.select name="severity" label="Severity" placeholder="Any severity" :selected="$filters->get('severity')" :options="['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']" />
        <x-form.select name="type" label="Signal" placeholder="Any signal" :selected="$filters->get('type')" :options="$types" />
        <x-form.input name="user" type="number" label="User ID" :value="$filters->get('user')" min="1" />
    </x-admin.filters>

    <x-admin.table caption="Risk flags" :columns="['Signal', 'User', 'Subject', 'Count', 'Last seen', 'Status']" :empty="$flags->isEmpty()" empty-title="No risk flags match">
        @foreach ($flags as $flag)
            <tr class="align-top">
                <td class="px-4 py-3">
                    <x-badge :variant="['high' => 'danger', 'medium' => 'warning', 'low' => 'neutral'][$flag->severity]">{{ ucfirst($flag->severity) }}</x-badge>
                    <p class="mt-1 text-ink-100">{{ $types[$flag->type] ?? $flag->type }}</p>
                    @if ($flag->details)
                        <dl class="mt-1 space-y-0.5 font-mono text-xs text-ink-400">
                            @foreach ($flag->details as $key => $value)
                                <div><dt class="inline">{{ $key }}:</dt> <dd class="inline">{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </td>
                <td class="px-4 py-3 text-ink-100">
                    @if ($flag->user)
                        @can('users.view')
                            <a href="{{ route('admin.users.show', $flag->user_id) }}" class="text-accent-300 hover:text-accent-200">{{ $flag->user->name }}</a>
                        @else
                            {{ $flag->user->name }}
                        @endcan
                        <span class="text-ink-400">#{{ $flag->user_id }}</span>
                    @else
                        <span class="text-ink-400">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-ink-400">{{ $flag->subject_type ? class_basename($flag->subject_type).' #'.$flag->subject_id : '—' }}</td>
                <td class="px-4 py-3 text-ink-300">{{ $flag->occurrences }} <span class="text-ink-400">/ {{ $flag->threshold }}</span></td>
                <td class="px-4 py-3 whitespace-nowrap text-ink-300"><x-datetime :value="$flag->last_seen_at" format="j M Y, g:i A" /></td>
                <td class="px-4 py-3">
                    <x-badge>{{ ucfirst($flag->status) }}</x-badge>
                    @if ($flag->reviewed_at)
                        <p class="mt-1 text-xs text-ink-400">{{ $flag->reviewer?->name }}, <x-datetime :value="$flag->reviewed_at" format="j M Y" /></p>
                        <p class="mt-1 max-w-xs text-xs text-ink-300">{{ $flag->review_note }}</p>
                    @elseif (in_array($flag->status, ['open', 'watching'], true))
                        <form method="POST" action="{{ route('admin.risk.review', $flag) }}" class="mt-2 w-64 space-y-2" data-loading>
                            @csrf
                            <label class="sr-only" for="note-{{ $flag->id }}">Review note</label>
                            <textarea id="note-{{ $flag->id }}" name="note" rows="2" required minlength="5" maxlength="500" placeholder="What you checked and concluded"
                                class="block w-full rounded-lg border border-ink-600 bg-ink-900 px-2 py-1.5 text-xs text-ink-100 placeholder:text-ink-400 focus:border-accent-400"></textarea>
                            <div class="flex gap-2">
                                <x-button type="submit" name="decision" value="reviewed" size="sm">Reviewed</x-button>
                                <x-button type="submit" name="decision" value="dismissed" variant="secondary" size="sm">Dismiss</x-button>
                            </div>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>
    @error('note')
        <x-alert variant="error">{{ $message }}</x-alert>
    @enderror
    <x-pagination :paginator="$flags" noun="flags" />
</x-admin.layout>
