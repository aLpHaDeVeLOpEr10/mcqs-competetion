@php($retry = isset($exception) && method_exists($exception, 'getHeaders') ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0)
<x-error-page code="429" title="Too many requests" :back="true">
    You have made too many requests in a short time.
    {{ $retry > 0 ? 'Please try again in '.$retry.' '.\Illuminate\Support\Str::plural('second', $retry).'.' : 'Please wait a minute and try again.' }}
</x-error-page>
