@if ($paginator->hasPages())
    <nav aria-label="Pagination">
        <ul class="flex flex-wrap items-center gap-1 text-sm">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="inline-flex rounded-lg px-3 py-2 text-ink-600" aria-disabled="true">Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex rounded-lg px-3 py-2 text-ink-300 hover:bg-ink-800 hover:text-white">Previous</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="inline-flex px-2 py-2 text-ink-400" aria-hidden="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="inline-flex min-w-9 justify-center rounded-lg bg-accent-400 px-3 py-2 font-semibold text-ink-950">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="Page {{ $page }}" class="inline-flex min-w-9 justify-center rounded-lg px-3 py-2 text-ink-300 hover:bg-ink-800 hover:text-white">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex rounded-lg px-3 py-2 text-ink-300 hover:bg-ink-800 hover:text-white">Next</a>
                @else
                    <span class="inline-flex rounded-lg px-3 py-2 text-ink-600" aria-disabled="true">Next</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
