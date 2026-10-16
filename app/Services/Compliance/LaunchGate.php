<?php

namespace App\Services\Compliance;

/**
 * The real-money launch gate (config/compliance.php). Enforced once per request/command at
 * boot: unless a recorded approval covers every requirement, the real-money switches are
 * forced off, whatever the environment sets. It decides nothing legal itself; it only
 * refuses to run real money until qualified review has been recorded in configuration.
 */
class LaunchGate
{
    /**
     * The switches the gate controls (config keys of competition.php).
     */
    public const SWITCHES = [
        'competition.paid_entries.enabled' => 'Paid entries',
        'competition.payments.deposits_enabled' => 'Deposits',
        'competition.prizes.payouts_enabled' => 'Prize payouts to wallets',
        'competition.withdrawals.enabled' => 'Withdrawals',
    ];

    /**
     * What the environment asked for before the gate ran.
     *
     * @var array<string, bool>
     */
    protected array $requested = [];

    public function enforce(): void
    {
        foreach (array_keys(self::SWITCHES) as $key) {
            $this->requested[$key] = (bool) config($key);
        }

        if (! $this->approved()) {
            config(array_fill_keys(array_keys(self::SWITCHES), false));
        }
    }

    public function approved(): bool
    {
        return config('compliance.real_money_approved') === true
            && filled(config('compliance.approval_reference'))
            && $this->missing() === [];
    }

    /**
     * Labels of the requirements not yet confirmed.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return collect(config('compliance.requirements', []))
            ->reject(fn (array $r) => ($r['confirmed'] ?? false) === true)
            ->pluck('label')->values()->all();
    }

    /**
     * Real-money switches the environment turned on (whether or not the gate allowed them).
     *
     * @return list<string>
     */
    public function requested(): array
    {
        return collect($this->requested)->filter()->keys()->map(fn ($key) => self::SWITCHES[$key])->values()->all();
    }

    /**
     * Requested but held off by the gate.
     *
     * @return list<string>
     */
    public function blocked(): array
    {
        return $this->approved() ? [] : $this->requested();
    }
}
