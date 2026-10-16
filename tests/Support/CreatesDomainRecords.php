<?php

namespace Tests\Support;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentTransaction;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Models\SessionQuestionOption;
use App\Models\Wallet;
use Illuminate\Support\Str;

/**
 * Raw record builders for schema tests. Later phases create these rows only
 * through services; tests here exercise the database guarantees directly.
 */
trait CreatesDomainRecords
{
    protected function makeLedgerTransaction(?string $key = null): LedgerTransaction
    {
        return tap((new LedgerTransaction)->forceFill([
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'type' => LedgerTransactionType::Adjustment,
            'currency' => 'PKR',
            'description' => 'test',
        ]))->save();
    }

    protected function makeLedgerEntry(LedgerTransaction $transaction, Wallet $wallet, int $amountMinor): LedgerEntry
    {
        return tap((new LedgerEntry)->forceFill([
            'ledger_transaction_id' => $transaction->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => $amountMinor,
            'balance_after_minor' => $amountMinor,
        ]))->save();
    }

    protected function makeSession(Room $room): CompetitionSession
    {
        return tap((new CompetitionSession)->forceFill(['room_id' => $room->id]))->save();
    }

    protected function makeSessionQuestion(CompetitionSession $session, int $position = 1, array $attributes = []): SessionQuestion
    {
        return tap((new SessionQuestion)->forceFill($attributes + [
            'competition_session_id' => $session->id,
            'position' => $position,
            'body' => "Question {$position}?",
            'time_limit_seconds' => 20,
        ]))->save();
    }

    protected function makeSessionOption(SessionQuestion $question, int $position, bool $correct = false): SessionQuestionOption
    {
        return tap((new SessionQuestionOption)->forceFill([
            'session_question_id' => $question->id,
            'position' => $position,
            'body' => "Option {$position}",
            'is_correct' => $correct,
        ]))->save();
    }

    protected function makeAnswer(SessionQuestion $question, RoomParticipant $participant, SessionQuestionOption $option): AnswerSubmission
    {
        return tap((new AnswerSubmission)->forceFill([
            'session_question_id' => $question->id,
            'room_participant_id' => $participant->id,
            'session_question_option_id' => $option->id,
            'received_at' => now(),
            'response_time_ms' => 1500,
            'is_correct' => $option->is_correct,
        ]))->save();
    }

    protected function makePayment(Wallet $wallet, string $key, ?string $gatewayReference = null): PaymentTransaction
    {
        return tap((new PaymentTransaction)->forceFill([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'type' => PaymentType::Deposit,
            'status' => PaymentStatus::Pending,
            'gateway' => 'fake',
            'amount_minor' => 10_000,
            'idempotency_key' => $key,
            'gateway_reference' => $gatewayReference,
        ]))->save();
    }
}
