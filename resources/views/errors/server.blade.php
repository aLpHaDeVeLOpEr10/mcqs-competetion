{{-- Self-contained server-error page: no layout, assets, session or database access. Never shows exception details. --}}
@php($reference = \Illuminate\Support\Facades\Context::get('request_id'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #070b14; color: #e6ecf7;
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; padding: 1rem; }
        main { max-width: 32rem; text-align: center; }
        .code { color: #5eead4; font-weight: 600; letter-spacing: .2em; font-size: .875rem; }
        h1 { font-size: 2rem; margin: .75rem 0 0; }
        p { color: #aebbd4; line-height: 1.6; }
        a { display: inline-block; margin-top: 1.5rem; padding: .7rem 1.25rem; border-radius: .5rem; background: #2dd4bf;
            color: #070b14; font-weight: 600; text-decoration: none; }
        a:focus-visible { outline: 2px solid #5eead4; outline-offset: 2px; }
        .ref { margin-top: 1rem; font-size: .8rem; color: #8a9bbd; }
        code { font-family: ui-monospace, monospace; color: #e6ecf7; word-break: break-all; }
    </style>
</head>
<body>
    <main>
        <p class="code">{{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if ($code === 500 && is_string($reference))
            <p class="ref">Reference: <code>{{ $reference }}</code></p>
        @endif
        <a href="{{ url('/') }}">Go to home</a>
    </main>
</body>
</html>
