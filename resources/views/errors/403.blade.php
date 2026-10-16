{{-- abort() messages in this application are written for players, so a specific reason is shown when there is one. --}}
@php($reason = isset($exception) ? trim($exception->getMessage()) : '')
<x-error-page code="403" title="Access denied" :back="true">
    {{ $reason !== '' && $reason !== 'This action is unauthorized.' ? $reason : 'You do not have permission to view this page.' }}
    @guest
        <span class="mt-2 block">If you have an account, <a href="{{ route('login') }}" class="text-accent-300 underline underline-offset-2">log in</a> and try again.</span>
    @endguest
</x-error-page>
