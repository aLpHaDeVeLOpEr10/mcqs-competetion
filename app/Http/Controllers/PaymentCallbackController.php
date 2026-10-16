<?php

namespace App\Http\Controllers;

use App\Models\PaymentCallback;
use App\Services\Payments\DepositService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Server-to-server notifications from payment providers. All authentication
 * and verification happens in the driver and DepositService; the response
 * reveals nothing beyond accepted / rejected.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, DepositService $deposits): Response
    {
        $outcome = $deposits->handleCallback($gateway, $request);

        return $outcome === PaymentCallback::REJECTED
            ? response('rejected', 400)->header('Content-Type', 'text/plain')
            : response('OK', 200)->header('Content-Type', 'text/plain');
    }
}
