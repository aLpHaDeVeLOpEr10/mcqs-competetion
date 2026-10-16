<x-account-layout title="My account">
    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold text-white">{{ $user->publicName() }}</p>
                <p class="truncate text-sm text-ink-400">{{ $user->email }}</p>
            </div>
            <x-badge :variant="$user->status === \App\Enums\UserStatus::Active ? 'accent' : 'warning'" dot>{{ $user->status->label() }}</x-badge>
        </div>
    </x-card>

    @if ($live)
        <x-alert variant="info" title="You are in a live competition">
            {{ $live->room->name }} is running now.
            <a href="{{ route('play.show', $live->room) }}" class="ml-1 font-semibold underline underline-offset-2">Enter the live competition</a>
        </x-alert>
    @endif

    {{-- Facts from the ledger and published results only. --}}
    <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-card>
            <dt class="text-sm text-ink-400">Wallet balance</dt>
            <dd class="mt-1 text-2xl font-bold text-white">{{ \App\Support\Money::format($wallet?->balance_minor ?? 0, $wallet?->currency) }}</dd>
            <dd><a href="{{ route('account.wallet') }}" class="mt-2 inline-flex text-sm text-accent-300 hover:text-accent-200">View wallet</a></dd>
        </x-card>
        <x-card>
            <dt class="text-sm text-ink-400">Upcoming</dt>
            <dd class="mt-1 text-2xl font-bold text-white">{{ $upcomingCount }}</dd>
            <dd class="mt-2 text-xs text-ink-400">competitions you are registered for</dd>
        </x-card>
        <x-card>
            <dt class="text-sm text-ink-400">Completed</dt>
            <dd class="mt-1 text-2xl font-bold text-white">{{ $completedCount }}</dd>
            <dd class="mt-2 text-xs text-ink-400">{{ $wins }} {{ \Illuminate\Support\Str::plural('win', $wins) }} (first place)</dd>
        </x-card>
        <x-card>
            <dt class="text-sm text-ink-400">Prizes received</dt>
            <dd class="mt-1 text-2xl font-bold text-white">{{ \App\Support\Money::format($prizesPaidMinor, $wallet?->currency) }}</dd>
            <dd class="mt-2 text-xs text-ink-400">credited to your wallet</dd>
        </x-card>
    </dl>

    <div class="grid gap-6 xl:grid-cols-2">
        <section aria-labelledby="upcoming-heading">
            <div class="flex items-center justify-between">
                <h2 id="upcoming-heading" class="text-lg font-semibold text-white">Upcoming competitions</h2>
                <a href="{{ route('account.competitions', ['tab' => 'upcoming']) }}" class="text-sm text-accent-300 hover:text-accent-200">View all</a>
            </div>
            @if ($upcoming->isEmpty())
                <x-empty-state title="Nothing coming up" :action="route('competitions.index')" action-label="Browse competitions" class="mt-4">
                    Competitions you join will appear here with their start time.
                </x-empty-state>
            @else
                <x-card class="mt-4" padding="p-0">
                    <ul class="divide-y divide-ink-800">
                        @foreach ($upcoming as $participation)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0">
                                    <a href="{{ route('account.competitions.show', $participation->room) }}" class="font-medium text-white hover:text-accent-300">{{ $participation->room->name }}</a>
                                    <p class="text-xs text-ink-400">
                                        @if ($participation->room->starts_at ?? $participation->room->scheduled_start_at)
                                            Starts <x-datetime :value="$participation->room->starts_at ?? $participation->room->scheduled_start_at" />
                                        @else
                                            Starts when the room fills ({{ $participation->room->participants_count }}/{{ $participation->room->max_participants }})
                                        @endif
                                    </p>
                                </div>
                                <x-status-badge :status="$participation->room->status" />
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </section>

        <section aria-labelledby="prizes-heading">
            <div class="flex items-center justify-between">
                <h2 id="prizes-heading" class="text-lg font-semibold text-white">Prize history</h2>
                <a href="{{ route('account.competitions.history') }}" class="text-sm text-accent-300 hover:text-accent-200">Competition history</a>
            </div>
            @if ($prizes->isEmpty())
                <x-empty-state title="No prizes yet" class="mt-4">Prizes you win are listed here once results are published.</x-empty-state>
            @else
                <x-card class="mt-4" padding="p-0">
                    <ul class="divide-y divide-ink-800">
                        @foreach ($prizes as $prize)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0">
                                    <a href="{{ route('account.competitions.show', $prize->participant->room) }}" class="font-medium text-white hover:text-accent-300">{{ $prize->participant->room->name }}</a>
                                    <p class="text-xs text-ink-400">Rank #{{ $prize->rank }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-white">{{ \App\Support\Money::format($prize->net_amount_minor ?? $prize->amount_minor, $prize->participant->room->currency) }}</p>
                                    @include('account.partials.payout-badge', ['payout' => $prize])
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </section>
    </div>

    <section aria-labelledby="recent-tx-heading">
        <div class="flex items-center justify-between">
            <h2 id="recent-tx-heading" class="text-lg font-semibold text-white">Recent wallet transactions</h2>
            <a href="{{ route('account.transactions') }}" class="text-sm text-accent-300 hover:text-accent-200">View all</a>
        </div>
        @if ($transactions->isEmpty())
            <x-empty-state title="No transactions yet" class="mt-4">Deposits, entry fees, refunds and prizes will appear here.</x-empty-state>
        @else
            @include('account.partials.ledger-table', ['entries' => $transactions])
        @endif
    </section>

    <section aria-labelledby="notifications-heading">
        <div class="flex items-center justify-between">
            <h2 id="notifications-heading" class="text-lg font-semibold text-white">Notifications
                @if ($unreadNotifications > 0)<x-badge variant="accent" class="ml-2 align-middle">{{ $unreadNotifications }} new</x-badge>@endif
            </h2>
            <a href="{{ route('account.notifications') }}" class="text-sm text-accent-300 hover:text-accent-200">View all</a>
        </div>
        @if ($notifications->isEmpty())
            <x-empty-state title="No notifications" class="mt-4">We will let you know here when something needs your attention, such as a prize credit.</x-empty-state>
        @else
            <x-card class="mt-4" padding="p-0">
                <ul class="divide-y divide-ink-800">
                    @foreach ($notifications as $notification)
                        @include('account.partials.notification', ['notification' => $notification])
                    @endforeach
                </ul>
            </x-card>
        @endif
    </section>
</x-account-layout>
