<?php

namespace Tests\Feature\Withdrawals;

use App\Enums\PayoutMethod;
use App\Models\AuditLog;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Services\Withdrawals\PayoutDestinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesWithdrawals;
use Tests\TestCase;

class PayoutDestinationTest extends TestCase
{
    use RefreshDatabase, UsesWithdrawals;

    // A published example IBAN with a valid ISO 13616 checksum.
    protected const IBAN = 'PK36SCBL0000001123456702';

    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'method' => 'bank_account', 'provider' => 'MEEZAN', 'account_title' => 'Test Holder',
            'account_number' => 'pk36 scbl 0000 0011 2345 6702', 'current_password' => 'password',
        ];
    }

    public function test_numbers_are_normalized_and_validated(): void
    {
        $this->assertSame(self::IBAN, PayoutDestinationService::normalize(PayoutMethod::BankAccount, 'pk36 scbl 0000 0011 2345 6702'));
        $this->assertNull(PayoutDestinationService::normalize(PayoutMethod::BankAccount, 'PK37SCBL0000001123456702'), 'Bad checksum.');
        $this->assertNull(PayoutDestinationService::normalize(PayoutMethod::BankAccount, 'GB82WEST12345698765432'), 'Pakistani IBANs only.');
        $this->assertSame('03001234567', PayoutDestinationService::normalize(PayoutMethod::MobileWallet, '+92 300 1234567'));
        $this->assertSame('03001234567', PayoutDestinationService::normalize(PayoutMethod::MobileWallet, '0300-1234567'));
        $this->assertNull(PayoutDestinationService::normalize(PayoutMethod::MobileWallet, '04001234567'));
    }

    public function test_adding_requires_the_password_and_stores_the_number_encrypted_and_masked(): void
    {
        $this->enableWithdrawals();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form(['current_password' => 'wrong']))
            ->assertSessionHasErrorsIn('destination', ['current_password']);
        $this->assertSame(0, PayoutDestination::query()->count());

        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form())->assertSessionHas('success');

        $destination = PayoutDestination::query()->sole();
        $this->assertSame(self::IBAN, $destination->account_number_encrypted, 'Decrypts to the normalized number.');
        $raw = DB::table('payout_destinations')->value('account_number_encrypted');
        $this->assertStringNotContainsString('1123456702', $raw, 'Stored encrypted.');
        $this->assertSame('6702', $destination->account_last4);
        $this->assertTrue($destination->usable_after->isFuture(), 'Cooling-off period applies.');
        $this->assertArrayNotHasKey('account_number_encrypted', $destination->toArray());

        $this->actingAs($user)->get(route('account.payout-destinations'))->assertOk()
            ->assertSee('Meezan Bank •••• 6702')->assertDontSee(self::IBAN)->assertDontSee('1123456702');
        $this->assertStringNotContainsString('1123456702', AuditLog::query()->get()->toJson());
        $this->assertSame('payout_destination_changed', $user->notifications()->sole()->data['type'], 'A security notice is sent.');
    }

    public function test_invalid_and_duplicate_accounts_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form(['account_number' => 'PK00BAD']))
            ->assertSessionHasErrorsIn('destination', ['account_number']);
        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form(['provider' => 'NOT_A_BANK']))
            ->assertSessionHasErrorsIn('destination', ['account_number']);

        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form())->assertSessionHas('success');
        $this->actingAs($user)->post(route('account.payout-destinations.store'), $this->form())
            ->assertSessionHasErrorsIn('destination', ['account_number']);
        $this->assertSame(1, PayoutDestination::query()->count());
    }

    public function test_removal_needs_the_password_and_only_works_on_own_accounts(): void
    {
        $owner = User::factory()->create();
        $destination = $this->destination($owner);
        $other = User::factory()->create();

        $this->actingAs($other)->delete(route('account.payout-destinations.destroy', $destination), ['current_password' => 'password'])->assertNotFound();
        $this->actingAs($owner)->delete(route('account.payout-destinations.destroy', $destination), ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('remove-'.$destination->id, ['current_password']);
        $this->assertSame('active', $destination->fresh()->status);

        $this->actingAs($owner)->delete(route('account.payout-destinations.destroy', $destination), ['current_password' => 'password'])->assertSessionHas('success');
        $this->assertSame('removed', $destination->fresh()->status, 'Kept for the record, never deleted.');
        $this->actingAs($other)->get(route('account.payout-destinations'))->assertDontSee('4567');
    }
}
