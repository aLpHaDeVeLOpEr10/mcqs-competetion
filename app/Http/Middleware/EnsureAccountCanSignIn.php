<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of any signed-in user whose account was suspended or
 * closed after they logged in. Runs on every web request, including the
 * admin area's separate session.
 */
class EnsureAccountCanSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user->status->canSignIn() || $user->trashed())) {
            // The current guard: "admin" inside the admin area, "web" everywhere else.
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account is not available. Please contact support if you think this is a mistake.';

            // Background requests (live play, notifications) get a clear 401, not a login page.
            return $request->expectsJson()
                ? response()->json(['message' => $message], 401)
                : redirect()->route(UseAdminSession::isAdminRequest($request) ? 'admin.login' : 'login')->with('error', $message);
        }

        return $next($request);
    }
}
