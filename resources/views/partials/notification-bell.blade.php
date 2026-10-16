{{--
    Header bell: unread count and the five latest notifications (server-rendered).
    resources/js/notifications.js adds live updates from the user's private channel.
--}}
@php
    $bellUser = auth()->user();
    $bellUnread = $bellUser->unreadNotificationCount();
    $bellRecent = $bellUser->recentNotifications();
@endphp
<details class="relative" data-notification-bell data-user-id="{{ $bellUser->id }}" data-mobile-menu>
    <summary class="relative grid size-10 place-items-center rounded-lg text-ink-300 hover:bg-ink-800 hover:text-white"
        aria-label="Notifications{{ $bellUnread ? ', '.$bellUnread.' unread' : '' }}">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></svg>
        <span data-bell-count @class(['absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-accent-400 px-1 text-center text-xs leading-5 font-bold text-ink-950', 'hidden' => $bellUnread === 0])>{{ $bellUnread > 99 ? '99+' : $bellUnread }}</span>
    </summary>
    <div class="fixed inset-x-4 top-16 mt-2 rounded-xl border border-ink-700 bg-ink-900 shadow-2xl sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:w-80">
        <div class="flex items-center justify-between border-b border-ink-700 px-4 py-3">
            <p class="text-sm font-semibold text-white">Notifications</p>
            @if ($bellUnread > 0)
                <form method="POST" action="{{ route('account.notifications.read') }}">
                    @csrf
                    <button type="submit" class="text-xs text-accent-300 hover:text-accent-200">Mark all as read</button>
                </form>
            @endif
        </div>
        <ul class="max-h-80 divide-y divide-ink-800 overflow-y-auto" data-bell-list>
            @forelse ($bellRecent as $notification)
                @include('account.partials.notification', ['notification' => $notification, 'compact' => true])
            @empty
                <li class="px-4 py-6 text-center text-sm text-ink-400" data-bell-empty>No notifications yet.</li>
            @endforelse
        </ul>
        <a href="{{ route('account.notifications') }}" class="block border-t border-ink-700 px-4 py-3 text-center text-sm text-accent-300 hover:text-accent-200">See all notifications</a>
    </div>
</details>
