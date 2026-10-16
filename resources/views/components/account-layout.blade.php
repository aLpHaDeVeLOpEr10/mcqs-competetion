@props(['title'])

@php
    $user = auth()->user();
    $links = [
        ['account.show', 'Overview'],
        ['account.competitions', 'My competitions'],
        ['account.competitions.history', 'History'],
        ['account.wallet', 'Wallet'],
        ['account.transactions', 'Transactions'],
        ['account.deposits.index', 'Deposits'],
        ['account.withdrawals.index', 'Withdrawals'],
        ['account.payout-destinations', 'Payout accounts'],
        ['account.notifications', 'Notifications'],
        ['account.profile', 'Profile'],
        ['account.security', 'Security'],
    ];
@endphp

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[14rem_1fr]">
            <nav aria-label="Account" class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <p class="px-3 text-xs font-semibold tracking-wide text-ink-400 uppercase">Account</p>
                <ul data-scroll-active class="scroll-fade mt-3 flex gap-1 overflow-x-auto pr-8 pb-2 text-sm font-medium lg:flex-col lg:overflow-visible lg:pr-0 lg:pb-0 lg:[mask-image:none]">
                    @foreach ($links as [$route, $label])
                        @php($active = request()->routeIs($route))
                        <li class="shrink-0">
                            <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                                @class(['block rounded-lg px-3 py-2', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => ! $active])>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="min-w-0 space-y-6">
                <h1 class="text-2xl font-bold text-white sm:text-3xl">{{ $title }}</h1>

                @if ($user->status === \App\Enums\UserStatus::Restricted)
                    <x-alert variant="warning" title="Your account is restricted">
                        You can sign in and view your account, but you cannot join competitions or make transactions.
                        @if ($user->status_reason) Reason: {{ $user->status_reason }}. @endif
                        Please <a href="{{ route('contact') }}" class="underline">contact support</a> for help.
                    </x-alert>
                @endif

                @if (\App\Models\User::requiresEmailVerification() && ! $user->hasVerifiedEmail())
                    <x-alert variant="info" title="Verify your email address">
                        <span>We sent a link to {{ $user->email }}. You need a verified email before joining competitions.</span>
                        <form method="POST" action="{{ route('verification.send') }}" class="mt-2" data-loading>
                            @csrf
                            <button type="submit" class="font-semibold underline underline-offset-2">Resend verification email</button>
                        </form>
                    </x-alert>
                @endif

                {{ $slot }}
            </div>
        </div>
    </div>
</x-layouts.app>
