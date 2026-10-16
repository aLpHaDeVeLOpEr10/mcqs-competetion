<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Account\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Same response whether or not the email was already registered.
     */
    public function store(RegisterRequest $request, RegistrationService $registration): RedirectResponse
    {
        $registration->register($request->safe()->only(['name', 'display_name', 'email', 'password']));

        return redirect()->route('login')->with('status', 'Thanks for signing up! We have sent an email to the address you entered. Follow it to continue, then sign in.');
    }
}
