<x-account-layout title="Notifications">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <nav aria-label="Notification filter">
            <ul class="flex gap-1 text-sm font-medium">
                @foreach (['all' => 'All', 'unread' => 'Unread ('.$unreadCount.')'] as $key => $label)
                    @php($active = ($key === 'unread') === $unreadOnly)
                    <li><a href="{{ route('account.notifications', $key === 'unread' ? ['filter' => 'unread'] : []) }}" @if ($active) aria-current="page" @endif
                        @class(['inline-flex rounded-lg px-3 py-2', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => ! $active])>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
        <div class="flex flex-wrap items-center gap-3">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('account.notifications.read') }}" data-loading>
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" data-loading-text="Updating…">Mark all as read</x-button>
                </form>
            @endif
            <a href="{{ route('account.notifications.preferences') }}" class="text-sm text-accent-300 hover:text-accent-200">Preferences</a>
        </div>
    </div>

    @if ($notifications->isEmpty())
        <x-empty-state :title="$unreadOnly ? 'No unread notifications' : 'No notifications'">
            We will let you know here when something needs your attention, such as a reminder, a result or a prize.
        </x-empty-state>
    @else
        <x-card padding="p-0">
            <ul class="divide-y divide-ink-800">
                @foreach ($notifications as $notification)
                    @include('account.partials.notification', ['notification' => $notification])
                @endforeach
            </ul>
        </x-card>
        <x-pagination :paginator="$notifications" noun="notifications" />
    @endif
</x-account-layout>
