<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Services\Payments\Gateways\FakePaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * Resolves the configured payment driver and enforces the safeguards around it.
 */
class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGateway>
     */
    protected array $resolved = [];

    public function __construct(protected Application $app) {}

    /**
     * The configured driver (config competition.payments.gateway).
     */
    public function gateway(): PaymentGateway
    {
        return $this->driver((string) config('competition.payments.gateway'));
    }

    /**
     * @throws LogicException for unknown drivers or a fake driver outside local/testing
     */
    public function driver(string $name): PaymentGateway
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $class = config("competition.payments.drivers.{$name}");
        if (! is_string($class) || ! is_subclass_of($class, PaymentGateway::class)) {
            throw new LogicException("Unknown payment gateway driver \"{$name}\".");
        }

        if ($class === FakePaymentGateway::class && ! $this->fakeAllowed()) {
            throw new LogicException('The fake payment gateway is only available in local/testing environments with FAKE_GATEWAY_ENABLED=true and a secret.');
        }

        return $this->resolved[$name] = $this->app->make($class);
    }

    /**
     * Safeguard for the simulated provider: never in production, and only when
     * explicitly switched on with its own secret.
     */
    public function fakeAllowed(): bool
    {
        return $this->app->environment(['local', 'testing'])
            && ! $this->app->isProduction()
            && config('competition.payments.fake.enabled') === true
            && filled(config('competition.payments.fake.secret'));
    }

    /**
     * Whether players may start deposits right now.
     */
    public function depositsAvailable(): bool
    {
        if (! config('competition.payments.deposits_enabled') || config('competition.payments.gateway') === 'disabled') {
            return false;
        }

        try {
            $this->gateway();
        } catch (LogicException) {
            return false;
        }

        return true;
    }

    public function forgetResolved(): void
    {
        $this->resolved = [];
    }
}
