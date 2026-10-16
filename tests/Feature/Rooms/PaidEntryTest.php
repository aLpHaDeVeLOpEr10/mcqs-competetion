<?php

namespace Tests\Feature\Rooms;

use App\Contracts\Payments\EntryPayment;
use App\Enums\JoinBlockReason;
use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Events\Rooms\RoomOccupancyChanged;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\EntryDeniedException;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Competition\RoomScheduler;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

class PaidEntryTest extends TestCase
{
    use CreatesRooms, RefreshDatabase;

    protected WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.paid_entries.enabled' => true]);
        $this->wallets = app(WalletService::class);
    }

    protected function paidRoom(int $fee = 5_000, array $attributes = []): Room
    {
        return $this->openRoom($attributes + ['entry_fee_minor' => $fee, 'min_participants' => 1, 'max_participants' => 10]);
    }

    protected function player(int $balance = 20_000, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        if ($balance > 0) {
            $this->wallets->credit($this->wallets->walletFor($user), $balance, LedgerTransactionType::Deposit, 'seed:'.$user->id);
        }

        return $user;
    }

    protected function balance(User $user): int
    {
        return $this->wallets->getAvailableBalance($user);
    }

    protected function join(User $user, Room $room): array
    {
        return app(RoomEntryService::class)->join($user, $room);
    }

    protected function denied(callable $attempt): JoinBlockReason
    {
        try {
            $attempt();
        } catch (EntryDeniedException $e) {
            return $e->reason;
        }
        $this->fail('Expected the join to be denied.');
    }

    protected function entryFees(): int
    {
        return LedgerTransaction::query()->where('type', LedgerTransactionType::EntryFee)->count();
    }

    public function test_a_paid_join_debits_the_frozen_fee_and_links_the_ledger_entry(): void
    {
        Event::fake([RoomOccupancyChanged::class]);
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);

        $response = $this->actingAs($user)->post(route('competitions.join', $room));

        $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $user->id)->sole();
        $charge = $seat->entryLedgerTransaction;
        $response->assertRedirect(route('competitions.show', $room))
            ->assertSessionHas('success', "You're in! Your seat is confirmed and Rs 50 was paid from your wallet (reference {$charge->reference}).");

        $this->assertSame(ParticipantStatus::Joined, $seat->status);
        $this->assertSame(5_000, $seat->entry_fee_minor);
        $this->assertSame($charge->reference, $seat->payment_reference);
        $this->assertSame(15_000, $this->balance($user));
        $this->assertSame(1, $room->fresh()->participants_count);

        // The ledger record: user, room, seat, amount, currency, reference, key, completed.
        $this->assertSame(LedgerTransactionType::EntryFee, $charge->type);
        $this->assertSame('PKR', $charge->currency);
        $this->assertTrue($charge->related->is($seat));
        $this->assertSame($room->id, $charge->metadata['room_id']);
        $this->assertStringStartsWith('entry_fee:'.$seat->id.':', $charge->idempotency_key);
        $this->assertSame('completed', $charge->status());
        $this->assertSame(-5_000, $charge->entries()->where('wallet_id', $user->wallet->id)->value('amount_minor'));
        $this->assertSame($charge->reference, AuditLog::query()->where('action', 'room.seat_joined')->sole()->new_values['entry_reference']);

        // Changing the room's fee afterwards cannot change what was charged.
        $room->forceFill(['entry_fee_minor' => 9_000])->save();
        $this->assertSame(5_000, $seat->fresh()->entry_fee_minor);

        Event::assertDispatched(RoomOccupancyChanged::class, fn ($e) => $e->roomId === $room->id && $e->participantsCount === 1);

        $this->get(route('competitions.show', $room))->assertOk()
            ->assertSee('Your entry')->assertSee('Confirmed')->assertSee('Rs 50')->assertSee($charge->reference)->assertSee('Competition rules');
    }

    public function test_insufficient_balance_takes_no_seat_and_no_money(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(4_999);
        Event::fake([RoomOccupancyChanged::class]);

        $this->actingAs($user)->get(route('competitions.show', $room))->assertSee('Add money to join')->assertSee('Go to your wallet');
        $this->actingAs($user)->post(route('competitions.join', $room))
            ->assertSessionHas('error', 'Your wallet balance is lower than the entry fee. You have not been charged.');

        $this->assertSame(0, RoomParticipant::query()->count());
        $this->assertSame(0, $room->fresh()->participants_count);
        $this->assertSame(4_999, $this->balance($user));
        $this->assertSame(0, $this->entryFees());
        Event::assertNotDispatched(RoomOccupancyChanged::class);
    }

    public function test_the_debit_itself_decides_even_if_the_page_check_passed(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(5_000);

        // The balance is spent between page load and join (the unlocked hint is stale).
        $this->wallets->debit($user->wallet, 1, LedgerTransactionType::Withdrawal, 'spend');

        $this->assertSame(JoinBlockReason::InsufficientBalance, $this->denied(fn () => $this->join($user, $room)));
        $this->assertSame(0, RoomParticipant::query()->count());
    }

    public function test_duplicate_joins_charge_once(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);

        $this->actingAs($user)->post(route('competitions.join', $room));
        $this->actingAs($user)->post(route('competitions.join', $room))->assertSessionHas('success', 'You already have a seat in this room.');
        $this->assertSame(RoomEntryService::ALREADY, $this->join($user, $room)['outcome']);

        $this->assertSame(15_000, $this->balance($user));
        $this->assertSame(1, $this->entryFees());
        $this->assertSame(1, $room->fresh()->participants_count);
    }

    public function test_a_failed_debit_rolls_back_the_join(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);
        $user->wallet->forceFill(['status' => WalletStatus::Frozen])->save();

        $this->assertSame(JoinBlockReason::PaymentFailed, $this->denied(fn () => $this->join($user->fresh(), $room)));

        $this->assertSame(0, RoomParticipant::query()->count());
        $this->assertSame(0, $room->fresh()->participants_count);
        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(0, $this->entryFees());
        $this->assertSame(0, AuditLog::query()->where('action', 'room.seat_joined')->count());

        // A returning player's earlier seat is left exactly as it was.
        $user->wallet->forceFill(['status' => WalletStatus::Active])->save();
        $this->join($user, $room);
        app(RoomEntryService::class)->withdraw($user, $room);
        $user->wallet->forceFill(['status' => WalletStatus::Frozen])->save();
        $this->denied(fn () => $this->join($user->fresh(), $room));
        $this->assertSame(ParticipantStatus::Withdrawn, RoomParticipant::query()->sole()->status);
        $this->assertSame(20_000, $this->balance($user));
    }

    public function test_a_failure_after_the_debit_rolls_the_debit_back(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);

        // The seat cannot be confirmed after the money was taken (e.g. a database error).
        RoomParticipant::updating(function (RoomParticipant $seat) {
            if ($seat->status === ParticipantStatus::Joined) {
                throw new RuntimeException('Simulated failure confirming the seat');
            }
        });

        try {
            $this->join($user, $room);
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure confirming the seat', $e->getMessage());
        }

        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(0, $this->entryFees());
        $this->assertSame(1, LedgerEntry::query()->where('wallet_id', $user->wallet->id)->count(), 'Only the seed deposit remains.');
        $this->assertSame(0, RoomParticipant::query()->count());
        $this->assertSame(0, $room->fresh()->participants_count);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_free_rooms_create_no_financial_records(): void
    {
        $room = $this->openRoom(['min_participants' => 1, 'max_participants' => 10]);
        $user = $this->player(0);

        $this->actingAs($user)->post(route('competitions.join', $room))->assertSessionHas('success', "You're in! Your seat is confirmed.");

        $seat = RoomParticipant::query()->sole();
        $this->assertSame(ParticipantStatus::Joined, $seat->status);
        $this->assertNull($seat->entry_ledger_transaction_id);
        $this->assertSame(0, LedgerTransaction::query()->count());
        $this->actingAs($user)->get(route('competitions.show', $room))->assertSee('Free entry');

        app(RoomLifecycle::class)->cancel($room, null, 'Test');
        $this->assertSame(RoomStatus::Cancelled, $room->fresh()->status, 'Nothing to refund: the room stays cancelled.');
        $this->assertSame(0, LedgerTransaction::query()->count());
    }

    public function test_cancellation_refunds_every_entry_exactly(): void
    {
        $room = $this->paidRoom(5_000);
        $players = collect([$this->player(20_000), $this->player(5_000), $this->player(7_500)]);
        $players->each(fn (User $user) => $this->join($user, $room));
        $this->assertSame(15_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));

        $manager = User::factory()->role(UserRole::CompetitionManager)->create();
        $this->actingAs($manager)->patch(route('admin.rooms.cancel', $room), ['reason' => 'Venue problem', 'confirm' => '1'])->assertSessionHas('success');

        $room->refresh();
        $this->assertSame(RoomStatus::Refunded, $room->status);
        $this->assertSame([20_000, 5_000, 7_500], $players->map(fn ($u) => $this->balance($u))->all());

        foreach (RoomParticipant::query()->with(['entryLedgerTransaction', 'refundLedgerTransaction'])->get() as $seat) {
            $this->assertSame(ParticipantStatus::Refunded, $seat->status);
            $this->assertNotNull($seat->refunded_at);
            $this->assertSame(LedgerTransactionType::EntryRefund, $seat->refundLedgerTransaction->type);
            $this->assertSame($seat->entry_ledger_transaction_id, $seat->refundLedgerTransaction->reverses_transaction_id);
            $this->assertStringContainsString('Venue problem', $seat->refundLedgerTransaction->description);
        }

        $this->assertSame(3, AuditLog::query()->where('action', 'room.entry_refunded')->count());
        $this->assertSame(0, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));
        $this->assertSame([], app(WalletReconciler::class)->check());

        $this->actingAs($players[0])->get(route('competitions.show', $room))->assertSee('Cancelled and refunded');
    }

    public function test_refunds_when_the_minimum_is_not_reached_or_the_competition_is_aborted(): void
    {
        $short = $this->paidRoom(5_000, ['min_participants' => 3, 'scheduled_start_at' => now()->addHour()]);
        $user = $this->player(20_000);
        $this->join($user, $short);

        $this->travel(61)->minutes();
        app(RoomScheduler::class)->advance();

        $this->assertSame(RoomStatus::Refunded, $short->fresh()->status);
        $this->assertSame(20_000, $this->balance($user));

        // Cancelled while live (before valid completion): playing seats are refunded too.
        $live = $this->paidRoom(5_000);
        $this->join($user, $live);
        $live->forceFill(['status' => RoomStatus::InProgress])->save();
        RoomParticipant::query()->where('room_id', $live->id)->update(['status' => ParticipantStatus::Playing->value]);
        app(RoomLifecycle::class)->cancel($live, null, 'Server incident');

        $this->assertSame(RoomStatus::Refunded, $live->fresh()->status);
        $this->assertSame(ParticipantStatus::Refunded, RoomParticipant::query()->where('room_id', $live->id)->sole()->status);
        $this->assertSame(20_000, $this->balance($user));
    }

    public function test_an_entry_fee_is_never_refunded_twice(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);
        $seat = $this->join($user, $room)['seat'];

        $first = $this->wallets->refundEntryFee($seat, 'manual check');
        $again = $this->wallets->refundEntryFee($seat->fresh(), 'manual check');
        app(EntryPayment::class)->release($room->fresh(), $seat->fresh(), 'room_cancelled');

        $this->assertTrue($first->is($again));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::EntryRefund)->count());
        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(1, AuditLog::query()->where('action', 'room.entry_refunded')->count());

        // Cancelling now refunds nothing more, and cannot be repeated.
        app(RoomLifecycle::class)->cancel($room, null, 'Test');
        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::EntryRefund)->count());
        $this->expectException(AdministrativeActionException::class);
        app(RoomLifecycle::class)->cancel($room->fresh(), null, 'Again');
    }

    public function test_withdrawal_refunds_by_policy_and_rejoining_charges_again(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);

        $this->join($user, $room);
        $this->actingAs($user)->post(route('competitions.withdraw', $room))
            ->assertSessionHas('success', 'You have left this room. Your entry fee of Rs 50 was refunded to your wallet.');
        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(ParticipantStatus::Withdrawn, RoomParticipant::query()->sole()->status);

        $this->travel(1)->seconds();
        $this->join($user, $room);
        $this->assertSame(15_000, $this->balance($user));
        $this->assertSame(2, $this->entryFees());
        $this->assertNull(RoomParticipant::query()->sole()->refund_ledger_transaction_id);

        // Policy: no refund on withdrawal.
        config(['competition.entry_fees.refund_on_withdrawal' => false]);
        app(RoomEntryService::class)->withdraw($user, $room);
        $this->assertSame(15_000, $this->balance($user));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::EntryRefund)->count());
    }

    public function test_unauthorized_users_cannot_join_or_be_charged(): void
    {
        $room = $this->paidRoom(5_000);

        $this->post(route('competitions.join', $room))->assertRedirect(route('login'));
        foreach ([['status' => UserStatus::Restricted], ['status' => UserStatus::Suspended]] as $attributes) {
            $user = $this->player(20_000, $attributes);
            $this->assertSame(JoinBlockReason::AccountRestricted, $this->denied(fn () => $this->join($user, $room)));
            $this->assertSame(20_000, $this->balance($user));
        }
        $unverified = User::factory()->unverified()->create();
        $this->assertSame(JoinBlockReason::EmailNotVerified, $this->denied(fn () => $this->join($unverified, $room)));

        $this->assertSame(0, RoomParticipant::query()->count());
        $this->assertSame(0, $this->entryFees());
    }

    public function test_joining_after_registration_closes_is_refused_without_charge(): void
    {
        $room = $this->paidRoom(5_000, ['scheduled_start_at' => now()->addHours(2), 'registration_closes_at' => now()->addHour()]);
        $user = $this->player(20_000);

        $this->travel(61)->minutes();

        $this->assertSame(JoinBlockReason::RegistrationClosed, $this->denied(fn () => $this->join($user, $room)));
        $this->assertSame(20_000, $this->balance($user));
        $this->assertSame(0, $this->entryFees());
    }

    public function test_paid_joins_are_refused_while_paid_entries_are_disabled(): void
    {
        $room = $this->paidRoom(5_000);
        $user = $this->player(20_000);
        config(['competition.paid_entries.enabled' => false]);

        $this->assertSame(JoinBlockReason::PaidEntryUnavailable, $this->denied(fn () => $this->join($user, $room)));
        $this->assertSame(20_000, $this->balance($user));
    }

    public function test_admins_see_charges_and_refunds_and_cannot_delete_entries(): void
    {
        $room = $this->paidRoom(5_000);
        $paid = $this->player(20_000);
        $refunded = $this->player(20_000);
        $this->join($paid, $room);
        $seat = $this->join($refunded, $room)['seat'];
        app(RoomEntryService::class)->withdraw($refunded, $room);
        $seat->refresh();

        $finance = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($finance)->get(route('admin.rooms.show', $room))->assertOk()
            ->assertSee($paid->display_name)->assertSee('Rs 50')
            ->assertSee($seat->entryLedgerTransaction->reference)->assertSee($seat->refundLedgerTransaction->reference)
            ->assertSee('Refunded')->assertSee('Not refunded');

        $deletes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('DELETE', $route->methods(), true) && preg_match('/participant|seat|ledger|entry/i', $route->uri()));
        $this->assertCount(0, $deletes);
    }
}
