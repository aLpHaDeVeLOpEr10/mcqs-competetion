<?php

namespace App\Http\Controllers\Account;

use App\Enums\PayoutMethod;
use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Models\PayoutDestination;
use App\Services\Withdrawals\PayoutDestinationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The player's own payout accounts. Adding or removing one requires the
 * current password (step-up), and the full number is never shown again.
 */
class PayoutDestinationController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.payout-destinations', [
            'destinations' => PayoutDestination::query()->where('user_id', $request->user()->id)->where('status', 'active')->latest('id')->get(),
            'methods' => PayoutMethod::cases(),
        ]);
    }

    public function store(Request $request, PayoutDestinationService $destinations): RedirectResponse
    {
        $data = $request->validateWithBag('destination', [
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'provider' => ['required', 'string', 'max:40'],
            'account_title' => ['required', 'string', 'min:3', 'max:100'],
            'account_number' => ['required', 'string', 'max:40'],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => 'The password is incorrect.']);

        try {
            $destinations->add($request->user(), PayoutMethod::from($data['method']), $data['provider'], $data['account_title'], $data['account_number']);
        } catch (AdministrativeActionException $e) {
            return back()->withInput($request->except(['account_number', 'current_password']))->withErrors(['account_number' => $e->getMessage()], 'destination');
        }

        return back()->with('success', 'Payout account saved. For your security it can be used after '.config('competition.withdrawals.destination_cooldown_hours').' hours.');
    }

    public function destroy(Request $request, PayoutDestination $destination, PayoutDestinationService $destinations): RedirectResponse
    {
        abort_unless($destination->user_id === $request->user()->id, 404);
        $request->validateWithBag('remove-'.$destination->id, ['current_password' => ['required', 'current_password']],
            ['current_password.current_password' => 'The password is incorrect.']);

        $destinations->remove($request->user(), $destination);

        return back()->with('success', 'Payout account removed.');
    }
}
