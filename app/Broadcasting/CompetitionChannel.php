<?php

namespace App\Broadcasting;

use App\Enums\ParticipantStatus;
use App\Models\CompetitionSession;
use App\Models\RoomParticipant;
use App\Models\User;

/**
 * private-competition.{session}: only users holding a confirmed seat in the
 * session's room may subscribe. Returns a boolean (private channel, not
 * presence), so no member list or user data is shared with other players.
 */
class CompetitionChannel
{
    public const SEATED = [ParticipantStatus::Joined, ParticipantStatus::Playing, ParticipantStatus::Finished];

    public function join(User $user, CompetitionSession $session): bool
    {
        return $user->status->canSignIn()
            && RoomParticipant::query()
                ->where('room_id', $session->room_id)
                ->where('user_id', $user->id)
                ->whereIn('status', self::SEATED)
                ->exists();
    }
}
