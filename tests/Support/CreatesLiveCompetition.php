<?php

namespace Tests\Support;

use App\Enums\RoomType;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\SessionQuestion;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Engine\CompetitionEngine;
use Illuminate\Support\Collection;

/**
 * Runs real rooms through join → starting → engine, using the production services.
 */
trait CreatesLiveCompetition
{
    use CreatesRooms;

    protected Room $room;

    protected CompetitionSession $session;

    /** @var Collection<int, User> */
    protected Collection $players;

    /**
     * A full fill room in "starting" (countdown not yet elapsed).
     */
    protected function startingCompetition(int $players = 3, array $room = []): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->room = $this->openRoom($room + [
            'type' => RoomType::Fill,
            'min_participants' => $players,
            'max_participants' => $players,
            'countdown_seconds' => 10,
            'question_gap_seconds' => 3,
        ]);
        $this->players = User::factory()->count($players)->create();

        foreach ($this->players as $player) {
            app(RoomEntryService::class)->join($player, $this->room);
        }

        $this->room->refresh();
        $this->session = $this->room->session()->firstOrFail();
    }

    /**
     * Prepared, started, first question delivered.
     */
    protected function liveCompetition(int $players = 3, array $room = []): void
    {
        $this->startingCompetition($players, $room);
        $this->advance();                 // prepare → countdown
        $this->travel(10)->seconds();
        $this->advance();                 // start → question 1 opens and is delivered
    }

    protected function advance(): void
    {
        app(CompetitionEngine::class)->advance($this->session->id);
        $this->session->refresh();
        $this->room->refresh();
    }

    protected function question(int $position): SessionQuestion
    {
        return $this->session->questions()->where('position', $position)->firstOrFail();
    }

    protected function answer(User $user, SessionQuestion $question, int $optionId)
    {
        return $this->actingAs($user)->postJson(route('play.answer', $this->room), [
            'question_id' => $question->id,
            'option_id' => $optionId,
        ]);
    }

    protected function optionId(SessionQuestion $question, bool $correct = true): int
    {
        return $question->options()->where('is_correct', $correct)->value('id');
    }
}
