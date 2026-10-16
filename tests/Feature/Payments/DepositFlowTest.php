<?php

namespace Tests\Feature\Payments;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentCallback;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\DepositService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class DepositFlowTest extends TestCase
{
    use RefreshDatabase, UsesFakeGateway;

    protected User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFakeGateway();
        $this->player = User::factory()->create();
    }

    protected function balance(?User $user = null): int
    {
        return app(WalletService::class)->getAvailableBalance($user ?? $this->player);
    }

    protected function depositCredits(): int
    {
        return LedgerTransaction::query()->where('type', LedgerTransactionType::Deposit)->count();
    }

    public function test_a_verified_successful_deposit_credits_the_wallet_once(): void
    {
        $token = (string) Str::uuid();
        $response = $this->actingAs($this->player)->post(route('account.deposits.store'), ['amount' => '1,250.50', 'token' => $token]);

        $payment = PaymentTransaction::query()->sole();
        $this->assertSame(125_050, $payment->amount_minor);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNotNull($payment->gateway_reference);
        $response->assertRedirect(route('fake-gateway.show', $payment->gateway_reference));

        // The simulated provider page; the player approves.
        $this->get(route('fake-gateway.show', $payment->gateway_reference))->assertOk()->assertSee('Rs 1,250.50');
        $this->post(route('fake-gateway.complete', $payment->gateway_reference), ['outcome' => 'succeeded'])
            ->assertRedirect(route('account.deposits.show', $payment->reference));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame(125_050, $this->balance());
        $this->assertSame(1, $this->depositCredits());
        $this->assertTrue($payment->ledgerTransaction->related->is($payment));

        $this->get(route('account.deposits.show', $payment->reference))->assertOk()->assertSee('Successful')->assertSee($payment->ledgerTransaction->reference);
        $this->get(route('account.deposits.index'))->assertOk()->assertSee($payment->reference)->assertSee('Rs 1,250.50');

        // Logged, without the signature.
        $callback = PaymentCallback::query()->sole();
        $this->assertSame(PaymentCallback::APPLIED, $callback->outcome);
        $this->assertTrue($callback->signature_valid);
        $this->assertSame('[removed]', $callback->payload['signature']);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_the_return_url_and_its_query_string_decide_nothing(): void
    {
        $payment = $this->startDeposit($this->player);

        $this->actingAs($this->player)
            ->get(route('account.deposits.show', ['payment' => $payment->reference, 'status' => 'succeeded', 'amount' => 50_000, 'paid' => 1]))
            ->assertOk()->assertSee('Awaiting payment');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_malformed_and_unauthenticated_callbacks_are_rejected(): void
    {
        $payment = $this->startDeposit($this->player);
        $state = $this->providerState($payment);

        // Missing fields.
        $this->deliver(['reference' => $payment->reference, 'status' => 'succeeded'])->assertStatus(400);

        // Signature made with another key.
        $forged = $this->fake()->notification(['status' => 'succeeded'] + $state);
        $forged['signature'] = hash_hmac('sha256', 'anything', 'wrong-secret');
        $this->deliver($forged)->assertStatus(400);

        // Genuine notification altered in transit (status flipped, original signature kept).
        $this->deliver($this->fake()->notification($state, ['status' => 'succeeded'], resign: false))->assertStatus(400);

        // Callbacks for an unknown or disabled gateway.
        $this->deliver($this->fake()->notification($state), 'nonexistent')->assertStatus(400);
        $this->deliver($this->fake()->notification($state), 'disabled')->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
        $this->assertSame(['malformed', 'bad_signature', 'bad_signature', 'unknown_gateway', 'gateway_disabled'],
            PaymentCallback::query()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(5, PaymentCallback::query()->where('outcome', PaymentCallback::REJECTED)->count());
    }

    public function test_inconsistent_callbacks_are_rejected(): void
    {
        $payment = $this->startDeposit($this->player, 50_000);
        $this->fake()->setProviderStatus($payment->reference, 'succeeded');
        $state = $this->providerState($payment);

        // Correctly signed, but the details do not match what we asked for.
        $this->deliver($this->fake()->notification($state, ['amount' => '5000000']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['currency' => 'USD']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['provider_reference' => 'FKOTHERPAYMENT']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['merchant_id' => 'OTHER-MERCHANT']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['reference' => (string) Str::ulid()]))->assertStatus(400);

        $this->assertSame(['amount_mismatch', 'currency_mismatch', 'provider_reference_mismatch', 'wrong_merchant', 'unknown_payment'],
            PaymentCallback::query()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_signed_success_is_not_trusted_when_the_provider_says_otherwise(): void
    {
        $payment = $this->startDeposit($this->player);

        // A validly signed "succeeded" notification, but the provider's own status query says pending.
        $this->deliver($this->fake()->notification(['status' => 'succeeded'] + $this->providerState($payment)))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
        $this->assertSame(PaymentCallback::PENDING, PaymentCallback::query()->sole()->outcome);
    }

    public function test_duplicate_callbacks_credit_exactly_once(): void
    {
        $payment = $this->startDeposit($this->player, 75_000);
        $notification = $this->fake()->complete($payment->gateway_reference, 'succeeded');

        foreach (range(1, 4) as $i) {
            $this->deliver($notification)->assertOk();
        }
        // A later, different notification for the same payment changes nothing either.
        $this->deliver($this->fake()->notification($this->providerState($payment)))->assertOk();
        app(DepositService::class)->refresh($payment->fresh());

        $this->assertSame(75_000, $this->balance());
        $this->assertSame(1, $this->depositCredits());
        $this->assertSame(1, LedgerEntry::query()->where('wallet_id', $payment->wallet_id)->count());
        $this->assertSame([PaymentCallback::APPLIED, PaymentCallback::DUPLICATE, PaymentCallback::DUPLICATE, PaymentCallback::DUPLICATE, PaymentCallback::DUPLICATE],
            PaymentCallback::query()->orderBy('id')->pluck('outcome')->all());
    }

    public function test_a_failed_payment_credits_nothing(): void
    {
        $payment = $this->startDeposit($this->player);

        $this->actingAs($this->player)->post(route('fake-gateway.complete', $payment->gateway_reference), ['outcome' => 'failed']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('failed', $payment->failure_code);
        $this->assertNull($payment->ledger_transaction_id);
        $this->assertSame(0, $this->balance());
        $this->get(route('account.deposits.show', $payment->reference))->assertSee('The payment was not completed');

        // A failed payment is final: a later success notification is ignored.
        $this->fake()->setProviderStatus($payment->reference, 'succeeded');
        $this->deliver($this->fake()->notification($this->providerState($payment)))->assertOk();
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_an_unpaid_payment_expires_and_a_late_verified_payment_is_still_credited(): void
    {
        $payment = $this->startDeposit($this->player);

        $this->travel(31)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame('expired_unpaid', $payment->fresh()->failure_code);
        $this->assertSame(0, $this->balance());

        // The player paid just after our deadline: the verified success is honoured, once.
        $notification = $this->fake()->complete($payment->gateway_reference, 'succeeded');
        $this->deliver($notification)->assertOk();
        $this->deliver($notification)->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(50_000, $this->balance());
        $this->assertSame(1, $this->depositCredits());
    }

    public function test_a_payment_the_provider_expired_is_marked_expired(): void
    {
        $payment = $this->startDeposit($this->player);
        $this->fake()->setProviderStatus($payment->reference, 'expired');

        $this->deliver($this->fake()->notification($this->providerState($payment)))->assertOk();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_provider_timeout_when_creating_takes_no_money_and_expires(): void
    {
        $this->fake()->failNext('create');

        $this->actingAs($this->player)->post(route('account.deposits.store'), ['amount' => '500', 'token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('error', 'The payment provider could not be reached. No money was taken; please try again in a few minutes.');

        $payment = PaymentTransaction::query()->sole();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertSame('provider_timeout', $payment->failure_code);
        $this->assertNull($payment->checkout);

        // The provider never registered it; after the deadline the reconciler expires it.
        $this->travel(31)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame('not_found_at_provider', $payment->fresh()->failure_code);
        $this->assertSame(0, $this->balance());
    }

    public function test_a_provider_timeout_during_verification_defers_and_reconciliation_settles_once(): void
    {
        $payment = $this->startDeposit($this->player);
        $notification = $this->fake()->complete($payment->gateway_reference, 'succeeded');

        $this->fake()->failNext('query');
        $this->deliver($notification)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status, 'Unverified: nothing credited yet.');
        $this->assertSame(0, $this->balance());
        $this->assertSame('verification_unavailable', PaymentCallback::query()->sole()->reason);

        // The callback was lost for good; the reconciler verifies with the provider.
        $this->travel(3)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(50_000, $this->balance());
        $this->assertSame(1, $this->depositCredits());
    }

    public function test_a_database_failure_while_crediting_rolls_back_and_a_retry_credits_once(): void
    {
        $payment = $this->startDeposit($this->player);
        $notification = $this->fake()->complete($payment->gateway_reference, 'succeeded');

        $fail = true;
        LedgerTransaction::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('Simulated database failure');
            }
        });

        try {
            app(DepositService::class)->handleCallback('fake', Request::create('/', 'POST', $notification));
            $this->fail('The failure must propagate so the provider retries.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->ledger_transaction_id);
        $this->assertSame(0, $this->balance());
        $this->assertSame(0, LedgerEntry::query()->count());

        // The provider retries the notification.
        $fail = false;
        $this->deliver($notification)->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(50_000, $this->balance());
        $this->assertSame(1, $this->depositCredits());
    }

    public function test_amount_and_limit_validation(): void
    {
        $post = fn (string $amount, ?string $token = null) => $this->actingAs($this->player)
            ->post(route('account.deposits.store'), ['amount' => $amount, 'token' => $token ?? (string) Str::uuid()]);

        $post('abc')->assertSessionHasErrors('amount');
        $post('99.99')->assertSessionHas('error', 'Enter an amount between Rs 100 and Rs 25,000.');
        $post('25000.01')->assertSessionHas('error');
        $this->assertSame(0, PaymentTransaction::query()->count());

        // Same form submitted twice: one deposit.
        $token = (string) Str::uuid();
        $post('100', $token);
        $post('100', $token);
        $this->assertSame(1, PaymentTransaction::query()->count());

        // At most three unfinished deposits.
        $post('100');
        $post('100');
        $post('100')->assertSessionHas('error', 'You have too many unfinished deposits. Complete or wait for them to expire first.');
        $this->assertSame(3, PaymentTransaction::query()->count());

        // Rs 50,000 per 24 hours (open and successful deposits count).
        $other = User::factory()->create();
        app(DepositService::class)->initiate($other, 2_500_000, (string) Str::uuid());
        $notification = $this->fake()->complete(PaymentTransaction::query()->where('user_id', $other->id)->value('gateway_reference'), 'succeeded');
        $this->deliver($notification);
        app(DepositService::class)->initiate($other, 2_400_000, (string) Str::uuid());
        $this->actingAs($other)->post(route('account.deposits.store'), ['amount' => '1000.01', 'token' => (string) Str::uuid()])
            ->assertSessionHas('error', 'This would exceed the deposit limit of Rs 50,000 per 24 hours.');
    }

    public function test_restricted_accounts_and_disabled_deposits_cannot_start_payments(): void
    {
        $restricted = User::factory()->create(['status' => UserStatus::Restricted]);
        $this->actingAs($restricted)->post(route('account.deposits.store'), ['amount' => '500', 'token' => (string) Str::uuid()])->assertForbidden();

        config(['competition.payments.deposits_enabled' => false]);
        $this->actingAs($this->player)->get(route('account.deposits.index'))->assertOk()->assertSee('Deposits are not available')->assertDontSee('Continue to payment');
        $this->actingAs($this->player)->post(route('account.deposits.store'), ['amount' => '500', 'token' => (string) Str::uuid()])
            ->assertSessionHas('error', 'Deposits are not available right now.');

        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_players_see_only_their_own_deposits(): void
    {
        $payment = $this->startDeposit($this->player);
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('account.deposits.show', $payment->reference))->assertNotFound();
        $this->actingAs($other)->get(route('account.deposits.index'))->assertDontSee($payment->reference);
        $this->signOut();
        $this->get(route('account.deposits.show', $payment->reference))->assertRedirect(route('login'));
    }

    public function test_the_fake_gateway_can_never_run_in_production(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $this->app->detectEnvironment(fn () => 'production');
        $manager->forgetResolved();
        $this->assertFalse($manager->fakeAllowed());
        $this->assertFalse($manager->depositsAvailable());
        try {
            $manager->driver('fake');
            $this->fail('The fake gateway must be refused in production.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // Even in testing it needs the explicit switch and a secret.
        $this->app->detectEnvironment(fn () => 'testing');
        foreach ([['competition.payments.fake.enabled' => false], ['competition.payments.fake.secret' => '']] as $override) {
            $this->enableFakeGateway();
            config($override);
            $manager->forgetResolved();
            $this->assertFalse($manager->fakeAllowed());
            $this->assertFalse($manager->depositsAvailable());
        }
    }

    public function test_the_callback_route_is_csrf_exempt_and_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('payments.callback');

        $this->assertContains(ValidateCsrfToken::class, $route->excludedMiddleware());
        $this->assertContains('throttle:provider-callbacks', $route->gatherMiddleware());
        $this->assertSame(['POST'], $route->methods());
    }
}
