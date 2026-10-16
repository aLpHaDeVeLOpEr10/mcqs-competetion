<?php

namespace Tests\Support;

use App\Enums\QuizPaperStatus;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomLifecycle;

/**
 * Builds rooms published through the real lifecycle (validation + snapshot).
 */
trait CreatesRooms
{
    protected function publishedPaper(): QuizPaper
    {
        $paper = QuizPaper::factory()->withQuestions(5)->create();
        $paper->forceFill(['status' => QuizPaperStatus::Published])->save();

        return $paper;
    }

    protected function draftRoom(array $attributes = []): Room
    {
        $defaults = ['quiz_paper_id' => $this->publishedPaper()->id];

        if (($attributes['type'] ?? null) === RoomType::Fill) {
            $defaults += ['scheduled_start_at' => null, 'registration_closes_at' => now()->addDay()];
        }

        return Room::factory()->create($attributes + $defaults);
    }

    protected function openRoom(array $attributes = []): Room
    {
        $room = $this->draftRoom($attributes);
        app(RoomLifecycle::class)->publish($this->staff(), $room);

        return $room->fresh();
    }

    protected function staff(): User
    {
        return User::factory()->role(UserRole::CompetitionManager)->create();
    }
}
