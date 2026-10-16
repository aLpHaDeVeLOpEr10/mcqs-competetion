<ul class="space-y-1 text-sm font-medium">
    @can('admin.access')
        <li><a href="{{ route('admin.dashboard') }}" class="block rounded-lg px-3 py-3 md:py-2 font-semibold text-accent-300 hover:bg-ink-800">Admin dashboard</a></li>
    @endcan
    @foreach ([['account.show', 'My account'], ['account.competitions', 'My competitions'], ['account.wallet', 'Wallet'], ['account.profile', 'Profile'], ['account.security', 'Security']] as [$route, $label])
        <li><a href="{{ route($route) }}" class="block rounded-lg px-3 py-3 md:py-2 text-ink-300 hover:bg-ink-800 hover:text-white">{{ $label }}</a></li>
    @endforeach
</ul>
<form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-ink-700 pt-1">
    @csrf
    <button type="submit" class="block w-full rounded-lg px-3 py-3 md:py-2 text-left text-sm font-medium text-ink-300 hover:bg-ink-800 hover:text-white">Sign out</button>
</form>
