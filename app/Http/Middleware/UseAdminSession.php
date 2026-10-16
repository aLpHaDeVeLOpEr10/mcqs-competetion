<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the admin area on its own session: /admin requests use a separate
 * session cookie (scoped to /admin) and the "admin" guard, so signing in or
 * out of the admin dashboard never touches the player's site session.
 * Must run before StartSession.
 */
class UseAdminSession
{
    /** The site's own session cookie and path, before any switching. */
    protected static ?array $site = null;

    public static function isAdminRequest(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        static::$site ??= [
            'cookie' => config('session.cookie'),
            'path' => config('session.path'),
        ];

        $admin = static::isAdminRequest($request);
        $cookie = $admin ? static::$site['cookie'].'_admin' : static::$site['cookie'];

        // Set both ways so long-lived processes (tests, Octane) never carry one area's settings into the other.
        if (config('session.cookie') !== $cookie) {
            config(['session.cookie' => $cookie, 'session.path' => $admin ? '/admin' : static::$site['path']]);
            // Rename the existing store rather than building a new one: the redirector and
            // error bag already hold this instance, and flashes must land in the saved session.
            app('session.store')->setName($cookie);
        }

        Auth::shouldUse($admin ? 'admin' : 'web');

        return $next($request);
    }
}
