<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\AdministrativeActionException;
use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Models\PayoutDestination;
use App\Models\Withdrawal;
use App\Services\Withdrawals\WithdrawalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Player withdrawals: choose an account and amount → review (amount, fee,
 * total, destination) → confirm → pending reference. Everything is re-checked
 * on the server when confirming; nothing relies on the page's balance.
 */
class WithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('account.withdrawals.index', [
            'enabled' => (bool) config('competition.withdrawals.enabled') && $user->can('transact'),
            'limits' => config('competition.withdrawals'),
            'balance' => (int) ($user->wallet?->balance_minor ?? 0),
            'destinations' => PayoutDestination::query()->where('user_id', $user->id)->where('status', 'active')->get(),
            'withdrawals' => Withdrawal::query()->where('user_id', $user->id)->latest('id')->paginate(15),
        ]);
    }

    public function review(Request $request, WithdrawalService $withdrawals): View|RedirectResponse
    {
        [$destination, $amount] = $this->input($request);

        if ($problem = $withdrawals->eligibilityProblem($request->user(), $amount, $destination)) {
            return back()->withInput()->with('error', $problem);
        }

        $fee = $withdrawals->fee($amount);

        return view('account.withdrawals.review', [
            'destination' => $destination,
            'amount' => $amount,
            'fee' => $fee,
            'balance' => (int) ($request->user()->wallet?->balance_minor ?? 0),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, WithdrawalService $withdrawals): RedirectResponse
    {
        $request->validate(['token' => ['required', 'uuid'], 'confirm' => ['accepted']]);
        [$destination, $amount] = $this->input($request);

        try {
            $withdrawal = $withdrawals->request($request->user(), $destination, $amount, $request->input('token'));
        } catch (WalletOperationException $e) {
            return redirect()->route('account.withdrawals.index')->with('error', $e->reason === WalletOperationException::INSUFFICIENT_FUNDS
                ? 'Your wallet balance is lower than the amount plus fee. Nothing was withdrawn.'
                : 'This withdrawal could not be accepted. Nothing was withdrawn.');
        } catch (AdministrativeActionException $e) {
            return redirect()->route('account.withdrawals.index')->with('error', $e->getMessage());
        }

        return redirect()->route('account.withdrawals.show', $withdrawal->reference)
            ->with('success', 'Withdrawal requested. Reference '.$withdrawal->reference.'. The money is held until it is paid or returned to your wallet.');
    }

    public function show(Request $request, Withdrawal $withdrawal): View
    {
        abort_unless($withdrawal->user_id === $request->user()->id, 404);

        return view('account.withdrawals.show', ['withdrawal' => $withdrawal->load(['holdTransaction:id,reference', 'releaseTransaction:id,reference'])]);
    }

    public function cancel(Request $request, Withdrawal $withdrawal, WithdrawalService $withdrawals): RedirectResponse
    {
        abort_unless($withdrawal->user_id === $request->user()->id, 404);

        try {
            $withdrawals->cancel($request->user(), $withdrawal);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Withdrawal cancelled. The money is back in your wallet.');
    }

    /**
     * @return array{0: ?PayoutDestination, 1: int}
     */
    protected function input(Request $request): array
    {
        $data = $request->validate([
            'destination_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
        ]);
        $amount = Money::parse($data['amount']);
        if ($amount === null) {
            back()->withInput()->withErrors(['amount' => 'Enter an amount in rupees, e.g. 1000 or 1000.50.'])->throwResponse();
        }

        // Only the user's own accounts are ever looked up.
        $destination = PayoutDestination::query()->where('user_id', $request->user()->id)->whereKey($data['destination_id'])->first();

        return [$destination, $amount];
    }
}
