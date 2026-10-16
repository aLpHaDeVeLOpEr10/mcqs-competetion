<?php

namespace Tests\Feature\Security;

use App\Enums\JoinBlockReason;
use App\Enums\LedgerTransactionType;
use App\Enums\UserRole;
use App\Exceptions\ContentValidationException;
use App\Models\User;
use App\Services\Competition\JoinEligibilityService;
use App\Services\Compliance\LaunchGate;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class LaunchGateTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    /**
     * Simulates the environment turning every real-money switch on, then the gate running at boot.
     */
    protected function boot(bool $approved, ?string $reference, array $unconfirmed = []): LaunchGate
    {
        config(array_fill_keys(array_keys(LaunchGate::SWITCHES), true));
        config(['compliance.real_money_approved' => $approved, 'compliance.approval_reference' => $reference]);
        foreach (array_keys(config('compliance.requirements')) as $key) {
            config(["compliance.requirements.{$key}.confirmed" => ! in_array($key, $unconfirmed, true)]);
        }

        $gate = new LaunchGate;
        $gate->enforce();
        $this->app->instance(LaunchGate::class, $gate);

        return $gate;
    }

    protected function openRoomFails(): ?string
    {
        try {
            $this->openRoom(['entry_fee_minor' => 5_000]);
        } catch (ContentValidationException $e) {
            return implode(' ', $e->problems);
        }

        return null;
    }

    protected function switchesOn(): array
    {
        return array_map(fn ($key) => (bool) config($key), array_keys(LaunchGate::SWITCHES));
    }

    public function test_by_default_every_requirement_is_unconfirmed_and_real_money_is_locked(): void
    {
        foreach (config('compliance.requirements') as $requirement) {
            $this->assertFalse($requirement['confirmed'], $requirement['label']);
        }
        $this->assertFalse(config('compliance.real_money_approved'));
        $this->assertFalse((new LaunchGate)->approved());
    }

    public function test_switches_set_in_the_environment_are_forced_off_without_a_complete_recorded_approval(): void
    {
        foreach ([
            'no approval' => [false, 'LEGAL-2026-01', []],
            'no reference' => [true, null, []],
            'one requirement open' => [true, 'LEGAL-2026-01', ['self_exclusion']],
        ] as $case => [$approved, $reference, $open]) {
            $gate = $this->boot($approved, $reference, $open);
            $this->assertSame([false, false, false, false], $this->switchesOn(), $case);
            $this->assertCount(4, $gate->blocked(), $case);
        }
    }

    public function test_a_complete_recorded_approval_leaves_the_switches_as_configured(): void
    {
        $gate = $this->boot(true, 'LEGAL-2026-01 / BOARD-2026-07');

        $this->assertTrue($gate->approved());
        $this->assertSame([true, true, true, true], $this->switchesOn());
        $this->assertSame([], $gate->blocked());
    }

    public function test_a_locked_gate_refuses_paid_entry_end_to_end_and_tells_staff(): void
    {
        // A paid room published while approved; then the approval no longer covers everything.
        $this->boot(true, 'LEGAL-2026-01');
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 1, 'max_participants' => 10]);
        $this->boot(true, 'LEGAL-2026-01', ['kyc']);
        $this->assertNotNull($this->openRoomFails(), 'A new paid room cannot even be published while locked.');
        $player = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($player), 20_000, LedgerTransactionType::Deposit, 'seed');

        $this->assertSame(JoinBlockReason::PaidEntryUnavailable, app(JoinEligibilityService::class)->check($room, $player)->reason);
        $this->actingAs($player)->post(route('competitions.join', $room));
        $this->assertSame(20_000, $wallets->getAvailableBalance($player), 'Nothing was charged.');

        $super = User::factory()->role(UserRole::SuperAdmin)->create();
        $this->actingAs($super)->get(route('admin.settings'))->assertOk()
            ->assertSee('LOCKED: real-money features are off')->assertSee('Identity verification (KYC)')->assertSee('Not confirmed');
        $this->actingAs($super)->get(route('admin.dashboard'))->assertSee('held OFF by the launch gate');

        $this->app['env'] = 'production';
        $this->artisan('security:check')->expectsOutputToContain('required production check(s) failed')->assertFailed();
    }
}
