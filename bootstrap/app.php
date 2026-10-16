<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountCanSignIn;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseAdminSession;
use App\Services\Security\RiskMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(prepend: [
            UseAdminSession::class,
        ], append: [
            EnsureAccountCanSignIn::class,
            SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => UseAdminSession::isAdminRequest($request) ? route('admin.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => UseAdminSession::isAdminRequest($request) ? route('admin.dashboard') : route('account.show'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flashed back into the session with old input after a validation error.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'account_number', 'iban', 'token']);

        // A signed-in user repeatedly hitting rate limits is a review signal (never a sanction).
        // Returning null keeps the default 429 response.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->user() !== null) {
                app(RiskMonitor::class)->signal('rate_limited', $request->user(), null, ['route' => $request->route()?->getName()]);
            }

            return null;
        });
    })->create();
