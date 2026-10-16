<nav aria-label="Admin" class="space-y-5 px-3 pb-6">
    @foreach ($sections as $heading => $items)
        @php($visible = array_filter($items, fn ($item) => auth()->user()->can($item[3])))
        @if ($visible !== [])
            <div>
                <p class="px-2 text-xs font-semibold tracking-wide text-ink-400 uppercase">{{ $heading }}</p>
                <ul class="mt-2 space-y-0.5 text-sm font-medium">
                    @foreach ($visible as [$route, $pattern, $label])
                        @php($active = request()->routeIs($pattern))
                        <li>
                            <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                                @class(['block rounded-lg px-2 py-1.5', 'bg-ink-800 text-white' => $active, 'text-ink-300 hover:bg-ink-800/60 hover:text-white' => ! $active])>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach
</nav>
