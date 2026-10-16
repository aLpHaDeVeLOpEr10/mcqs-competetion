<?php

namespace Database\Factories;

use App\Enums\ParticipantStatus;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Inserts a seat directly; it does not update rooms.participants_count or
 * touch the ledger. Use the entry service for realistic joins.
 *
 * @extends Factory<RoomParticipant>
 */
class RoomParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'status' => ParticipantStatus::Joined,
            'entry_fee_minor' => 0,
            'joined_at' => now(),
        ];
    }
}
