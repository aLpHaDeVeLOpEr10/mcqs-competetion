<x-admin.layout title="Users & roles" :breadcrumbs="['Users' => null]">
    <x-admin.filters :action="route('admin.users.index')" :filters="$filters">
        <x-form.input name="q" label="Search" :value="$filters->get('q')" placeholder="Name, display name or email" />
        <x-form.select name="role" label="Role" placeholder="Any role" :selected="$filters->get('role')"
            :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" />
        <x-form.select name="status" label="Status" placeholder="Any status" :selected="$filters->get('status')"
            :options="collect(\App\Enums\UserStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" />
    </x-admin.filters>

    <x-admin.table caption="Users" :columns="['User', 'Email', 'Role', 'Status', 'Joined']" :empty="$users->isEmpty()" empty-title="No users match">
        @foreach ($users as $user)
            <tr>
                <td>
                    <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-white hover:text-accent-300">{{ $user->name }}</a>
                    <p class="text-xs text-ink-400">{{ $user->display_name ?? '—' }}</p>
                </td>
                <td class="text-ink-300">
                    {{ $user->email }}
                    @unless ($user->email_verified_at)<span class="text-xs text-amber-300">(unverified)</span>@endunless
                </td>
                <td><x-badge :variant="$user->role->isStaff() ? 'info' : 'neutral'">{{ $user->role->label() }}</x-badge></td>
                <td><x-badge :variant="$user->status === \App\Enums\UserStatus::Active ? 'accent' : 'warning'">{{ $user->status->label() }}</x-badge></td>
                <td class="whitespace-nowrap text-ink-400"><x-datetime :value="$user->created_at" format="j M Y" /></td>
            </tr>
        @endforeach
    </x-admin.table>
    <x-pagination :paginator="$users" noun="users" />
</x-admin.layout>
