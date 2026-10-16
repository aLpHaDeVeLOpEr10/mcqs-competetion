<x-admin.layout title="Announcements" :breadcrumbs="['Announcements' => null]">
    <x-card as="section" aria-labelledby="new-announcement-heading">
        <h2 id="new-announcement-heading" class="text-lg font-semibold text-white">New announcement</h2>
        <p class="mt-1 text-sm text-ink-400">Sent as an in-app notification (and email for users who chose it). Plain text only. You are recorded as the author, and the send is audited.</p>
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="mt-5 space-y-5" data-loading novalidate>
            @csrf
            <x-form.input name="title" label="Title" maxlength="120" required />
            <x-form.textarea name="body" label="Message" rows="4" maxlength="2000" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.select name="audience" label="Audience" required :selected="old('audience', 'players')"
                    :options="collect(\App\Enums\AnnouncementAudience::cases())->mapWithKeys(fn ($a) => [$a->value => $a->label()])->all()" />
                <x-form.select name="room_id" label="Competition (for “Players in one competition”)" placeholder="—" :options="$rooms" :selected="old('room_id')" />
            </div>
            <x-form.checkbox name="confirm">I have checked the text and the audience. This is sent immediately and cannot be recalled.</x-form.checkbox>
            <x-button type="submit" data-loading-text="Sending…">Send announcement</x-button>
        </form>
    </x-card>

    <x-admin.table caption="Sent announcements" :columns="['Sent', 'Title', 'Audience', 'Recipients', 'Author']" :empty="$announcements->isEmpty()" empty-title="No announcements yet">
        @foreach ($announcements as $announcement)
            <tr>
                <td class="whitespace-nowrap text-ink-300">
                    @if ($announcement->sent_at)<x-datetime :value="$announcement->sent_at" format="j M Y, g:i A" />@else <x-badge variant="info">Queued</x-badge> @endif
                </td>
                <td class="text-white">{{ $announcement->title }}<p class="max-w-md truncate text-xs text-ink-400">{{ $announcement->body }}</p></td>
                <td class="text-ink-300">{{ $announcement->audience->label() }}@if ($announcement->room)<p class="text-xs text-ink-400">{{ $announcement->room->name }}</p>@endif</td>
                <td class="text-ink-300">{{ $announcement->sent_at ? number_format($announcement->recipients_count) : '—' }}</td>
                <td class="text-ink-300">{{ $announcement->author?->name }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$announcements" noun="announcements" />
</x-admin.layout>
