<?php

namespace App\Contracts\Payments;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Exceptions\Payments\PayoutRefusedException;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * An external payout provider (bank / mobile-wallet disbursement). Like
 * PaymentGateway, all provider-specific code lives in implementations, which
 * must follow the provider's official documentation, never guesses.
 */
interface PayoutGateway
{
    public function name(): string;

    /**
     * Whether outcomes can be confirmed with a server-to-server status query.
     */
    public function supportsStatusQuery(): bool;

    /**
     * Ask the provider to pay. $request->reference is the idempotency key: calling
     * again with the same reference must not pay twice.
     *
     * @throws PayoutRefusedException when the provider definitively refuses (nothing will be paid)
     * @throws GatewayException when the outcome is unknown (timeout, outage)
     */
    public function createPayout(PayoutRequest $request): PayoutResult;

    /**
     * Authenticate and parse a provider notification.
     *
     * @throws InvalidCallbackException
     */
    public function handleCallback(Request $request): PayoutResult;

    /**
     * @throws GatewayException
     */
    public function queryPayout(Withdrawal $withdrawal): PayoutResult;
}
