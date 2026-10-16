<?php

namespace Tests\Feature\Payments;

use App\Enums\UserRole;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class AdminPaymentsTest extends TestCase
{
    use RefreshDatabase, UsesFakeGateway;

    public function test_finance_staff_can_list_search_and_inspect_payments(): void
    {
        $this->enableFakeGateway();
        $player = User::factory()->create(['email' => 'payer@example.test']);
        $paid = $this->startDeposit($player, 80_000);
        $this->deliver($this->fake()->complete($paid->gateway_reference, 'succeeded'));
        $failed = $this->startDeposit(User::factory()->create(), 30_000);
        $this->deliver($this->fake()->complete($failed->gateway_reference, 'failed'));
        $finance = User::factory()->role(UserRole::FinanceManager)->create();

        $this->actingAs($finance)->get(route('admin.payments.index'))
            ->assertOk()->assertSee($paid->reference)->assertSee($failed->reference)->assertSee('Successful')->assertSee('Failed');

        foreach ([$paid->reference, $paid->gateway_reference, 'payer@example'] as $term) {
            $this->actingAs($finance)->get(route('admin.payments.index', ['q' => $term]))
                ->assertOk()->assertSee($paid->reference)->assertDontSee($failed->reference);
        }
        $this->actingAs($finance)->get(route('admin.payments.index', ['status' => 'failed']))
            ->assertSee($failed->reference)->assertDontSee($paid->reference);

        $paid->refresh();
        $this->actingAs($finance)->get(route('admin.payments.show', $paid))
            ->assertOk()
            ->assertSee($paid->gateway_reference)
            ->assertSee($paid->ledgerTransaction->reference)
            ->assertSee('Applied')
            ->assertSee('Staff cannot change a payment')
            ->assertDontSee(self::FAKE_SECRET)
            ->assertDontSee('[removed]');
    }

    public function test_players_and_other_staff_cannot_see_payments_and_no_route_changes_them(): void
    {
        $this->enableFakeGateway();
        $payment = $this->startDeposit(User::factory()->create());

        foreach ([UserRole::Player, UserRole::ContentManager, UserRole::CompetitionManager] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(route('admin.payments.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.payments.show', $payment))->assertForbidden();
        }

        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'payments') && str_starts_with($route->uri(), 'admin'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);
        $this->assertCount(0, $writes, 'Staff must not be able to mark payments as paid or edit them.');
        $this->assertInstanceOf(PaymentTransaction::class, $payment);
    }
}
