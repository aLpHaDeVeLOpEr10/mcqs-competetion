<x-admin.layout title="Settings" :breadcrumbs="['Settings' => null]">
    <x-alert variant="info" title="Read-only">
        These values come from the server environment and change only through deployment. Editing settings from this screen is not available.
    </x-alert>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($settings as $group => $values)
            <x-card as="section">
                <h2 class="font-semibold text-white">{{ $group }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach ($values as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-ink-400">{{ $label }}</dt><dd class="text-right break-all text-ink-100">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-card>
        @endforeach
    </div>

    <section aria-labelledby="roles-heading">
        <h2 id="roles-heading" class="text-lg font-semibold text-white">Roles and permissions</h2>
        <p class="mt-1 text-sm text-ink-400">Defined in code (App\Enums\UserRole). Every permission is enforced on the server for each request.</p>
        <x-admin.table class="mt-3" caption="Permission matrix" :columns="array_merge(['Permission'], $roles->map->label()->all())">
            @foreach ($permissions as $permission)
                <tr>
                    <td class="text-ink-100">{{ $permission->label() }}<p class="font-mono text-xs text-ink-400">{{ $permission->value }}</p></td>
                    @foreach ($roles as $role)
                        <td class="text-center">
                            @if ($role->grants($permission))
                                <span class="text-accent-300" aria-label="Granted">✓</span>
                            @else
                                <span class="text-ink-600" aria-label="Not granted">—</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </x-admin.table>
    </section>
</x-admin.layout>
