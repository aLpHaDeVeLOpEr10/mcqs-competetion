<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Notifications\PasswordChanged;
use App\Services\Account\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(protected SessionService $sessions) {}

    public function show(Request $request): View
    {
        return view('account.security', [
            'sessions' => $this->sessions->forUser($request->user(), $request->session()->getId()),
            'sessionsSupported' => $this->sessions->supported(),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $password = $request->validated('password');

        $user->forceFill(['password' => $password])->save();

        // Invalidate other sessions and remember-me cookies, then rotate this session's ID.
        Auth::logoutOtherDevices($password);
        $this->sessions->revokeOthers($user, $request->session()->getId());
        $request->session()->regenerate();

        $user->notify(new PasswordChanged);

        return redirect()->route('account.security')->with('success', 'Your password has been changed and your other sessions were signed out.');
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validateWithBag('sessions', [
            'password' => ['required', 'string', 'current_password'],
        ], ['password.current_password' => 'The password is incorrect.']);

        Auth::logoutOtherDevices($request->input('password'));
        $count = $this->sessions->revokeOthers($request->user(), $request->session()->getId());

        $message = $count === 1 ? 'Signed out 1 other session.' : 'Signed out '.$count.' other sessions.';

        return redirect()->route('account.security')->with('success', $message);
    }
}
