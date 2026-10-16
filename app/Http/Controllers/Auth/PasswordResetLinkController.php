<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * The response never depends on whether the address is registered,
     * including the broker's per-user throttle.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If an account exists for that email address, we have sent a password reset link. It expires in '.config('auth.passwords.users.expire').' minutes.');
    }
}
