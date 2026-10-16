<x-admin.layout :title="$user->name" :breadcrumbs="['Users' => route('admin.users.index'), $user->name => null]">
    <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="space-y-6">
            <x-card>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-400">Display name</dt><dd class="mt-1 text-white">{{ $user->display_name ?? '—' }}</dd></div>
                    <div><dt class="text-ink-400">Email</dt><dd class="mt-1 text-white">{{ $user->email }} {{ $user->email_verified_at ? '(verified)' : '(unverified)' }}</dd></div>
                    <div><dt class="text-ink-400">Role</dt><dd class="mt-1"><x-badge variant="info">{{ $user->role->label() }}</x-badge></dd></div>
                    <div><dt class="text-ink-400">Status</dt><dd class="mt-1"><x-badge :variant="$user->status === \App\Enums\UserStatus::Active ? 'accent' : 'warning'">{{ $user->status->label() }}</x-badge>
                        @if ($user->status_reason)<p class="mt-1 text-xs text-ink-400">{{ $user->status_reason }}</p>@endif</dd></div>
                    <div><dt class="text-ink-400">Joined</dt><dd class="mt-1 text-white"><x-datetime :value="$user->created_at" /></dd></div>
                    <div><dt class="text-ink-400">Competition seats</dt><dd class="mt-1 text-white">{{ $user->room_participations_count }}</dd></div>
                    @can('finance.view')
                        <div><dt class="text-ink-400">Wallet balance</dt><dd class="mt-1 text-white">{{ $wallet ? \App\Support\Money::format($wallet->balance_minor, $wallet->currency) : 'No wallet' }}</dd></div>
                    @endcan
                </dl>
            </x-card>

            <section aria-labelledby="history-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="history-heading" class="text-lg font-semibold text-white">Account history</h2>
                    <a href="{{ route('admin.users.report', $user) }}" class="text-sm text-accent-300 hover:text-accent-200">Full user report</a>
                </div>
                <x-admin.table class="mt-3" caption="Audit records for this user" :columns="['When', 'Action', 'Actor', 'Subject']" :empty="$history->isEmpty()" empty-title="No recorded changes">
                    @foreach ($history as $log)
                        @include('admin.audit.row', ['log' => $log])
                    @endforeach
                </x-admin.table>
            </section>
        </div>

        <aside class="space-y-4" aria-label="Actions">
            <h2 class="text-lg font-semibold text-white">Actions</h2>

            @can('changeStatus', $user)
                <x-admin.confirm-action title="Change account status" :action="route('admin.users.status', $user)" method="PATCH" bag="status" submit-label="Update status" danger>
                    <x-form.select name="status" id="status-status" label="New status" required bag="status" :selected="old('status')"
                        :options="collect(\App\Enums\UserStatus::cases())->reject(fn ($s) => $s === $user->status)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
                    <p class="text-xs text-ink-400">Suspended or closed accounts are signed out everywhere immediately. Restricted accounts can sign in but cannot join competitions or transact.</p>
                </x-admin.confirm-action>
            @else
                <p class="rounded-xl border border-ink-700 px-4 py-3 text-sm text-ink-400">
                    You cannot change this account's status{{ auth()->user()->is($user) ? ' (it is your own account)' : '' }}.
                </p>
            @endcan

            @can('changeRole', $user)
                <x-admin.confirm-action title="Change role" :action="route('admin.users.role', $user)" method="PATCH" bag="role" submit-label="Update role">
                    <x-form.select name="role" id="role-role" label="New role" required bag="role" :selected="old('role')"
                        :options="collect(\App\Enums\UserRole::cases())->reject(fn ($r) => $r === $user->role)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" />
                </x-admin.confirm-action>
            @endcan

            <x-admin.unavailable feature="Password resets on behalf of users, account deletion and data export">are not available. Users reset their own passwords.</x-admin.unavailable>
        </aside>
    </div>
</x-admin.layout>
