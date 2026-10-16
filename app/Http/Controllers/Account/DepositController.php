<?php

namespace App\Http\Controllers\Account;

use App\Enums\PaymentType;
use App\Exceptions\Payments\DepositException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DepositRequest;
use App\Models\PaymentTransaction;
use App\Services\Payments\DepositService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DepositController extends Controller
{
    public function index(Request $request, PaymentGatewayManager $gateways): View
    {
        return view('account.deposits.index', [
            'available' => $gateways->depositsAvailable() && $request->user()->can('transact'),
            'limits' => config('competition.payments.deposit'),
            'token' => (string) Str::uuid(),
            'deposits' => PaymentTransaction::query()
                ->where('user_id', $request->user()->id)->where('type', PaymentType::Deposit)
                ->latest('id')->paginate(15),
        ]);
    }

    public function store(DepositRequest $request, DepositService $deposits): Response
    {
        try {
            $payment = $deposits->initiate($request->user(), $request->amountMinor(), $request->validated('token'));
        } catch (DepositException $e) {
            return back()->withInput($request->only('amount'))->with('error', $e->getMessage());
        }

        $checkout = $payment->checkout;
        if (! $payment->status->isOpen() || ! $checkout) {
            return redirect()->route('account.deposits.show', $payment->reference);
        }

        // Hand the player to the provider. The outcome is decided later by the
        // verified callback / status query, never by where the browser comes back from.
        return $checkout['method'] === 'post'
            ? response()->view('account.deposits.checkout', ['payment' => $payment, 'checkout' => $checkout])
            : redirect()->away($checkout['url']);
    }

    /**
     * Status page, also the provider's return URL. Any query-string parameters
     * the provider appends are ignored; the status comes from our database,
     * refreshed by a server-side status query at most every 10 seconds.
     */
    public function show(Request $request, PaymentTransaction $payment, DepositService $deposits): View
    {
        abort_unless($payment->user_id === $request->user()->id && $payment->type === PaymentType::Deposit, 404);

        if ($payment->status->isOpen() && Cache::add('deposit-refresh:'.$payment->id, true, 10)) {
            $deposits->refresh($payment);
            $payment->refresh();
        }

        return view('account.deposits.show', [
            'payment' => $payment->load('ledgerTransaction:id,reference'),
        ]);
    }
}
