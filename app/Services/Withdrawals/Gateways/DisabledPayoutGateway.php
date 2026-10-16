<?php

namespace App\Services\Withdrawals\Gateways;

use App\Contracts\Payments\PayoutGateway;
use App\Contracts\Payments\PayoutRequest;
use App\Contracts\Payments\PayoutResult;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * The default: no payout provider is configured. Nothing is ever paid out and
 * no withdrawal can move to "processing" or "paid".
 */
class DisabledPayoutGateway implements PayoutGateway
{
    public function name(): string
    {
        return 'disabled';
    }

    public function supportsStatusQuery(): bool
    {
        return false;
    }

    public function createPayout(PayoutRequest $request): PayoutResult
    {
        throw GatewayException::unavailable('No payout provider is configured, so no money can be sent.');
    }

    public function handleCallback(Request $request): PayoutResult
    {
        throw new InvalidCallbackException('gateway_disabled');
    }

    public function queryPayout(Withdrawal $withdrawal): PayoutResult
    {
        throw GatewayException::unavailable('No payout provider is configured.');
    }
}
