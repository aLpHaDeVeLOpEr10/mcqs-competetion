@props(['value', 'format' => 'D, j M Y · g:i A'])

<time datetime="{{ $value->toIso8601String() }}" {{ $attributes }}>{{ \App\Support\DisplayTime::format($value, $format) }} {{ \App\Support\DisplayTime::zoneLabel() }}</time>
