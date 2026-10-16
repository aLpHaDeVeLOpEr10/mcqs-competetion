<?php

namespace App\Contracts\Payments;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * A payment provider driver. Provider-specific code (endpoints, field names,
 * hashing, merchant identity) lives ONLY in implementations of this
 * interface; deposit and wallet logic never sees it.
 *
 * Drivers must implement the provider's documented behaviour, never guess it.
 * Capabilities tell DepositService how an outcome can be verified.
 */
interface PaymentGateway
{
    /**
     * Driver name stored on payment_transactions.gateway.
     */
    public function name(): string;

    /**
     * Whether the provider offers a server-to-server transaction status query.
     * When true, every outcome is confirmed with queryPaymentStatus() before
     * money moves; when false the authenticated callback is the confirmation.
     */
    public function supportsStatusQuery(): bool;

    public function supportsRefunds(): bool;

    /**
     * Register the payment with the provider and say how to send the player there.
     *
     * @throws GatewayException when the provider is unreachable, times out or refuses
     */
    public function createPayment(PaymentRequest $request): CheckoutSession;

    /**
     * Authenticate (signature, merchant identity) and parse a provider
     * notification. Signatures must be compared in constant time.
     *
     * @throws InvalidCallbackException
     */
    public function handleCallback(Request $request): GatewayResult;

    /**
     * Ask the provider, server to server, for the payment's current status.
     *
     * @throws GatewayException
     */
    public function queryPaymentStatus(PaymentTransaction $payment): GatewayResult;

    /**
     * @throws GatewayException (including when refunds are unsupported)
     */
    public function refundPayment(PaymentTransaction $payment, int $amountMinor, string $reason): GatewayResult;
}
