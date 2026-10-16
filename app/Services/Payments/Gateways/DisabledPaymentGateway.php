<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\GatewayResult;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentRequest;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * The default: no provider is configured, so every operation is refused and
 * every callback is rejected. Nothing pretends to work.
 */
class DisabledPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'disabled';
    }

    public function supportsStatusQuery(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createPayment(PaymentRequest $request): CheckoutSession
    {
        throw GatewayException::unavailable('No payment provider is configured.');
    }

    public function handleCallback(Request $request): GatewayResult
    {
        throw new InvalidCallbackException('gateway_disabled');
    }

    public function queryPaymentStatus(PaymentTransaction $payment): GatewayResult
    {
        throw GatewayException::unavailable('No payment provider is configured.');
    }

    public function refundPayment(PaymentTransaction $payment, int $amountMinor, string $reason): GatewayResult
    {
        throw GatewayException::unavailable('No payment provider is configured.');
    }
}
