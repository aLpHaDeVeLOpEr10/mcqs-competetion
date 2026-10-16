<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if (! User::requiresEmailVerification() || $request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.show');
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('account.show')->with('success', 'Thanks — your email address is verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! User::requiresEmailVerification() || $request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.show');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }
}
