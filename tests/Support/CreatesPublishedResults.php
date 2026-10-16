<?php

namespace Tests\Support;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Models\CompetitionSession;
use App\Models\ParticipantResult;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;

/**
 * Builds a completed room with ranked results for public-page tests.
 */
trait CreatesPublishedResults
{
    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $players  [display name, score, rank]
     */
    protected function completedRoom(array $players, bool $published = true, array $roomAttributes = []): Room
    {
        $room = Room::factory()->status(RoomStatus::Completed)->create($roomAttributes + [
            'max_participants' => max(2, count($players)),
        ]);

        $session = tap((new CompetitionSession)->forceFill([
            'room_id' => $room->id,
            'status' => 'completed',
            'results_published_at' => $published ? now() : null,
        ]))->save();

        foreach ($players as [$name, $score, $rank]) {
            $participant = RoomParticipant::factory()->create([
                'room_id' => $room->id,
                'user_id' => User::factory()->create(['display_name' => $name])->id,
                'status' => ParticipantStatus::Finished,
            ]);

            (new ParticipantResult)->forceFill([
                'competition_session_id' => $session->id,
                'room_participant_id' => $participant->id,
                'score' => $score,
                'correct_count' => $score,
                'answered_count' => $score,
                'rank' => $rank,
                'computed_at' => now(),
            ])->save();
        }

        $room->forceFill(['participants_count' => count($players)])->save();

        return $room;
    }
}
