<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
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

        // Prevent session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('account.show'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->endSession($request);

        return redirect()->route('home')->with('success', 'You have been signed out.');
    }

    protected function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
