<?php

namespace App\Events\Rooms;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public "seats taken" update for a room page. Contains only the count,
 * capacity and status, which the page already shows to anyone: never who
 * joined, and nothing financial. It is a hint; the page is authoritative.
 */
class RoomOccupancyChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $roomId,
        public readonly int $participantsCount,
        public readonly int $maxParticipants,
        public readonly string $status,
    ) {}

    /**
     * Announce the room's committed occupancy once the current transaction
     * commits (immediately when there is none). Never sent for rolled-back
     * changes, and a WebSocket failure never affects the caller.
     */
    public static function afterCommit(int $roomId): void
    {
        DB::afterCommit(function () use ($roomId) {
            try {
                $room = Room::query()->find($roomId, ['id', 'participants_count', 'max_participants', 'status']);
                if ($room !== null) {
                    event(new static($room->id, $room->participants_count, $room->max_participants, $room->status->value));
                }
            } catch (Throwable $e) {
                Log::warning('Room occupancy broadcast failed.', ['room_id' => $roomId, 'error' => $e->getMessage()]);
            }
        });
    }

    public function broadcastOn(): array
    {
        return [new Channel('rooms.'.$this->roomId)];
    }

    public function broadcastAs(): string
    {
        return 'room.occupancy';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'participants_count' => $this->participantsCount,
            'max_participants' => $this->maxParticipants,
            'status' => $this->status,
        ];
    }
}
