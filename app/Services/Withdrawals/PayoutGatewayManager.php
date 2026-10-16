<?php

namespace App\Services\Withdrawals;

use App\Contracts\Payments\PayoutGateway;
use App\Services\Withdrawals\Gateways\FakePayoutGateway;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * Resolves the configured payout driver. The simulated driver is refused
 * outside local/testing, without its switch, or without a secret.
 */
class PayoutGatewayManager
{
    public function __construct(protected Application $app) {}

    public function gateway(): PayoutGateway
    {
        return $this->driver((string) config('competition.withdrawals.gateway'));
    }

    public function driver(string $name): PayoutGateway
    {
        $class = config("competition.withdrawals.drivers.{$name}");
        if (! is_string($class) || ! is_subclass_of($class, PayoutGateway::class)) {
            throw new LogicException("Unknown payout gateway driver \"{$name}\".");
        }

        if ($class === FakePayoutGateway::class && ! $this->fakeAllowed()) {
            throw new LogicException('The fake payout gateway is only available in local/testing environments with FAKE_PAYOUT_ENABLED=true and a secret.');
        }

        return $this->app->make($class);
    }

    public function fakeAllowed(): bool
    {
        return $this->app->environment(['local', 'testing'])
            && ! $this->app->isProduction()
            && config('competition.withdrawals.fake.enabled') === true
            && filled(config('competition.withdrawals.fake.secret'));
    }

    /**
     * Whether a real (non-disabled) payout provider can be used.
     */
    public function payoutsAvailable(): bool
    {
        if (config('competition.withdrawals.gateway') === 'disabled') {
            return false;
        }

        try {
            $this->gateway();
        } catch (LogicException) {
            return false;
        }

        return true;
    }
}
