@php
    $tabs = [['admin.reports.index', [], 'Overview', 'reports.view'], ['admin.reports.competitions', [], 'Competitions', 'reports.view'],
        ['admin.reports.finance', ['report' => 'reconciliation'], 'Reconciliation', 'finance.view']];
    foreach (\App\Services\Reports\ReportCatalog::REPORTS as $key => $definition) {
        if ($key !== 'competitions') {
            $tabs[] = ['admin.reports.finance', ['report' => $key], $definition['title'], 'finance.view'];
        }
    }
    $current = request()->route()->getName().'|'.(request()->route('report') ?? (request()->routeIs('admin.reports.finance') ? 'reconciliation' : ''));
@endphp
<nav aria-label="Reports" class="-mx-1 overflow-x-auto">
    <ul class="flex min-w-max gap-1 px-1 text-sm font-medium">
        @foreach ($tabs as [$route, $params, $label, $permission])
            @can($permission)
                @php($active = $current === $route.'|'.($params['report'] ?? ''))
                <li><a href="{{ route($route, $params + $period->toQuery()) }}" @if ($active) aria-current="page" @endif
                    @class(['inline-flex rounded-lg px-3 py-2', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => ! $active])>{{ $label }}</a></li>
            @endcan
        @endforeach
    </ul>
</nav>
