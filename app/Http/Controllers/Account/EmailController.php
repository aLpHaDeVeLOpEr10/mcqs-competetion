<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeEmailRequest;
use App\Services\Account\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct(protected EmailChangeService $emails) {}

    public function store(ChangeEmailRequest $request): RedirectResponse
    {
        $this->emails->request($request->user(), $request->validated('email'));

        return redirect()->route('account.profile')->with('success', 'We have sent a confirmation link to your new email address. Your email will change once you open it.');
    }

    public function confirm(Request $request, string $hash): RedirectResponse
    {
        return match ($this->emails->confirm($request->user(), $hash)) {
            EmailChangeService::CONFIRMED => redirect()->route('account.profile')->with('success', 'Your email address has been changed.'),
            EmailChangeService::UNAVAILABLE => redirect()->route('account.profile')->with('error', 'That email address cannot be used. Please try a different one.'),
            default => redirect()->route('account.profile')->with('error', 'This confirmation link is invalid or has already been used.'),
        };
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->emails->cancel($request->user());

        return redirect()->route('account.profile')->with('success', 'The pending email change was cancelled.');
    }
}
