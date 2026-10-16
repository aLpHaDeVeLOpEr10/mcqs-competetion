<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin dashboard sign-in. Runs on the "admin" guard and its own session
 * (UseAdminSession), independent of the player's site sign-in.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        // Status is revealed only after the correct password has been given.
        if (! $user->status->canSignIn()) {
            $this->endSession($request);

            throw ValidationException::withMessages([
                'email' => 'This account is '.$user->status->value.'. Please contact support for help.',
            ]);
        }

        if (Gate::forUser($user)->denies('admin.access')) {
            $this->endSession($request);

            throw ValidationException::withMessages([
                'email' => 'This account does not have access to the admin dashboard.',
            ]);
        }

        // Prevent session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->endSession($request);

        return redirect()->route('admin.login')->with('status', 'You have been signed out of the admin dashboard.');
    }

    protected function endSession(Request $request): void
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
