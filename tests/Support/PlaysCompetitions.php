<?php

namespace Tests\Support;

use App\Enums\LedgerTransactionType;
use App\Enums\RoomType;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\AnswerService;
use App\Services\Engine\CompetitionEngine;
use App\Services\Wallet\WalletService;

/**
 * Plays a whole competition through the real services: publish (with prize
 * tiers), join, engine, answers at chosen times, until it ends.
 */
trait PlaysCompetitions
{
    use CreatesLiveCompetition;

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3?: int}>  $tiers  [from, to, amount_minor, percent_bp]
     */
    protected function competitionWithPrizes(int $players, array $tiers, array $room = []): void
    {
        $this->travelTo(now()->startOfSecond());

        $draft = $this->draftRoom($room + [
            'type' => RoomType::Fill, 'min_participants' => $players, 'max_participants' => $players,
            'countdown_seconds' => 10, 'question_gap_seconds' => 3,
        ]);
        foreach ($tiers as $tier) {
            $draft->prizeTiers()->create(['rank_from' => $tier[0], 'rank_to' => $tier[1], 'amount_minor' => $tier[2], 'percent_bp' => $tier[3] ?? null]);
        }
        app(RoomLifecycle::class)->publish($this->staff(), $draft);

        $this->room = $draft->fresh();
        $this->players = User::factory()->count($players)->create();
        foreach ($this->players as $player) {
            if ($this->room->entry_fee_minor > 0) {
                // Paid rooms: fund the wallet so the real entry-fee debit succeeds.
                $wallets = app(WalletService::class);
                $wallets->credit($wallets->walletFor($player), $this->room->entry_fee_minor, LedgerTransactionType::Deposit, 'test-funds:'.$player->id);
            }
            app(RoomEntryService::class)->join($player, $this->room);
        }
        $this->session = $this->room->session()->firstOrFail();

        $this->advance();             // prepare
        $this->travel(10)->seconds();
        $this->advance();             // start, question 1 delivered
    }

    /**
     * $plan[position][playerIndex] = [afterOpenMs, correct?]  (missing = no answer)
     */
    protected function playOut(array $plan): void
    {
        $total = $this->session->questions()->count();

        for ($position = 1; $position <= $total; $position++) {
            $question = $this->question($position);
            if ($question->delivered_at === null) {
                $this->travelTo($question->opens_at);
                $this->advance();
                $question = $this->question($position);
            }

            $answers = $plan[$position] ?? [];
            uasort($answers, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($answers as $index => [$afterMs, $correct]) {
                $this->travelTo($question->opens_at->copy()->addMilliseconds($afterMs));
                app(AnswerService::class)->submit($this->players[$index], $this->room, $question->id, $this->optionId($question, $correct));
            }

            $this->travelTo($question->closes_at->copy()->addSecond());
            app(CompetitionEngine::class)->advance($this->session->id);
            $this->session->refresh();
            $this->room->refresh();
        }
    }
}
