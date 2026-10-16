{{-- One of the user's own notifications. Text is always escaped; links go through "open" (marks it read). --}}
@php
    $data = $notification->data;
    $title = $data['title'] ?? match ($data['type'] ?? null) {
        'prize_credited' => 'You won a prize',
        default => 'Notification',
    };
    $body = $data['body'] ?? (($data['type'] ?? null) === 'prize_credited'
        ? 'You won '.\App\Support\Money::format((int) ($data['amount_minor'] ?? 0)).' (rank #'.($data['rank'] ?? '?').'). It has been added to your wallet.'
        : null);
    $unread = $notification->read_at === null;
    $compact = $compact ?? false;
@endphp
<li @class(['flex items-start gap-3 px-4 py-3 text-sm', 'bg-accent-400/5' => $unread])>
    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-accent-400' => $unread, 'bg-transparent' => ! $unread]) aria-hidden="true"></span>
    <div class="min-w-0 flex-1">
        <a href="{{ route('account.notifications.open', $notification->id) }}" class="font-medium break-words text-white hover:text-accent-300">
            @if ($unread)<span class="sr-only">Unread: </span>@endif{{ $title }}
        </a>
        @if ($body && ! $compact)
            <p class="mt-0.5 break-words whitespace-pre-line text-ink-300">{{ $body }}</p>
        @endif
        <p class="mt-0.5 text-xs text-ink-400"><x-datetime :value="$notification->created_at" format="j M Y, g:i A" /></p>
    </div>
    @if ($unread && ! $compact)
        <form method="POST" action="{{ route('account.notifications.mark', $notification->id) }}" class="shrink-0">
            @csrf
            <button type="submit" class="text-xs text-ink-400 underline underline-offset-2 hover:text-white">Mark as read</button>
        </form>
    @endif
</li>
