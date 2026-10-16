<?php

namespace Tests\Feature\Database;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\RoomPrizeTier;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesDomainRecords;
use Tests\TestCase;

class IntegrityConstraintsTest extends TestCase
{
    use CreatesDomainRecords, RefreshDatabase;

    public function test_a_user_cannot_join_the_same_room_twice(): void
    {
        $participant = RoomParticipant::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        RoomParticipant::factory()->create([
            'room_id' => $participant->room_id,
            'user_id' => $participant->user_id,
        ]);
    }

    public function test_room_participant_count_cannot_exceed_capacity(): void
    {
        $room = Room::factory()->create(['min_participants' => 2, 'max_participants' => 2]);

        $room->forceFill(['participants_count' => 2])->save();
        $this->assertSame(2, $room->fresh()->participants_count);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('rooms_capacity_valid');

        DB::table('rooms')->where('id', $room->id)->increment('participants_count');
    }

    public function test_room_capacity_must_cover_minimum_participants(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('rooms_capacity_valid');

        Room::factory()->create(['min_participants' => 10, 'max_participants' => 5]);
    }

    public function test_entry_fee_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);

        Room::factory()->create(['entry_fee_minor' => -100]);
    }

    public function test_room_status_rejects_unknown_values(): void
    {
        $room = Room::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('rooms')->where('id', $room->id)->update(['status' => 'paused']);
    }

    public function test_a_question_can_have_at_most_one_correct_option(): void
    {
        $question = Question::factory()->create();
        QuestionOption::factory()->for($question)->create(['position' => 1, 'is_correct' => true]);
        QuestionOption::factory()->for($question)->create(['position' => 2, 'is_correct' => false]);
        QuestionOption::factory()->for($question)->create(['position' => 3, 'is_correct' => false]);

        $this->expectException(UniqueConstraintViolationException::class);

        QuestionOption::factory()->for($question)->create(['position' => 4, 'is_correct' => true]);
    }

    public function test_option_positions_are_unique_per_question(): void
    {
        $question = Question::factory()->create();
        QuestionOption::factory()->for($question)->create(['position' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        QuestionOption::factory()->for($question)->create(['position' => 1]);
    }

    public function test_question_time_limit_is_bounded(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('questions_time_limit_range');

        Question::factory()->create(['default_time_limit_seconds' => 2]);
    }

    public function test_prize_tier_rank_range_must_be_valid(): void
    {
        $room = Room::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('room_prize_tiers_rank_range');

        RoomPrizeTier::create(['room_id' => $room->id, 'rank_from' => 3, 'rank_to' => 1, 'amount_minor' => 0]);
    }

    public function test_ledger_idempotency_keys_are_unique(): void
    {
        $this->makeLedgerTransaction('entry:room-1:user-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeLedgerTransaction('entry:room-1:user-1');
    }

    public function test_ledger_entries_cannot_be_zero(): void
    {
        $wallet = Wallet::factory()->create();
        $transaction = $this->makeLedgerTransaction();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ledger_entries_amount_non_zero');

        $this->makeLedgerEntry($transaction, $wallet, 0);
    }

    public function test_payment_idempotency_keys_are_unique(): void
    {
        $wallet = Wallet::factory()->create();
        $this->makePayment($wallet, 'deposit-abc');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makePayment($wallet, 'deposit-abc');
    }

    public function test_gateway_reference_is_unique_per_gateway_but_may_be_null(): void
    {
        $wallet = Wallet::factory()->create();
        $this->makePayment($wallet, 'a');
        $this->makePayment($wallet, 'b');
        $this->makePayment($wallet, 'c', 'GW-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makePayment($wallet, 'd', 'GW-1');
    }

    public function test_user_wallet_balance_cannot_go_negative(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('wallets_user_balance_non_negative');

        $wallet->forceFill(['balance_minor' => -1])->save();
    }

    public function test_system_wallet_balance_may_go_negative(): void
    {
        $wallet = Wallet::factory()->system(Wallet::SYSTEM_GATEWAY_CLEARING)->create();

        $wallet->forceFill(['balance_minor' => -5000])->save();

        $this->assertSame(-5000, $wallet->fresh()->balance_minor);
    }

    public function test_wallet_owner_must_match_its_type(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('wallets_owner_matches_type');

        Wallet::factory()->create(['system_code' => Wallet::SYSTEM_PRIZE_POOL]);
    }

    public function test_a_user_has_one_wallet_per_currency(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->for($user)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Wallet::factory()->for($user)->create();
    }

    public function test_a_room_has_only_one_competition_session(): void
    {
        $room = Room::factory()->create();
        $this->makeSession($room);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeSession($room);
    }

    public function test_a_participant_can_answer_a_question_only_once(): void
    {
        $participant = RoomParticipant::factory()->create();
        $question = $this->makeSessionQuestion($this->makeSession($participant->room));
        $a = $this->makeSessionOption($question, 1, true);
        $b = $this->makeSessionOption($question, 2);

        $this->makeAnswer($question, $participant, $a);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeAnswer($question, $participant, $b);
    }

    public function test_selected_option_must_belong_to_the_answered_question(): void
    {
        $participant = RoomParticipant::factory()->create();
        $session = $this->makeSession($participant->room);
        $first = $this->makeSessionQuestion($session, 1);
        $second = $this->makeSessionQuestion($session, 2);
        $this->makeSessionOption($first, 1, true);
        $foreignOption = $this->makeSessionOption($second, 1, true);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('answer_submissions_option_belongs_to_question');

        $this->makeAnswer($first, $participant, $foreignOption);
    }

    public function test_a_participant_can_receive_only_one_prize_payout(): void
    {
        $participant = RoomParticipant::factory()->create();
        $session = $this->makeSession($participant->room);

        $distributionId = DB::table('prize_distributions')->insertGetId([
            'competition_session_id' => $session->id,
            'total_amount_minor' => 0,
            'calculation' => '{}',
            'calculated_at' => now(),
        ]);

        $payout = fn (string $key) => DB::table('prize_payouts')->insert([
            'prize_distribution_id' => $distributionId,
            'room_participant_id' => $participant->id,
            'rank' => 1,
            'amount_minor' => 100,
            'idempotency_key' => $key,
        ]);

        $payout('payout-1');

        $this->expectException(UniqueConstraintViolationException::class);

        $payout('payout-2');
    }

    public function test_a_session_has_only_one_prize_distribution(): void
    {
        $session = $this->makeSession(Room::factory()->create());
        $row = [
            'competition_session_id' => $session->id,
            'total_amount_minor' => 0,
            'calculation' => '{}',
            'calculated_at' => now(),
        ];

        DB::table('prize_distributions')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('prize_distributions')->insert($row);
    }
}
