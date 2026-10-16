<?php

namespace App\Http\Controllers;

use App\Models\PaymentCallback;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Server-to-server notifications from payout providers. Authentication and
 * verification happen in the driver and WithdrawalService.
 */
class WithdrawalCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, WithdrawalService $withdrawals): Response
    {
        $outcome = $withdrawals->handleCallback($gateway, $request);

        return response($outcome === PaymentCallback::REJECTED ? 'rejected' : 'OK', $outcome === PaymentCallback::REJECTED ? 400 : 200)
            ->header('Content-Type', 'text/plain');
    }
}
