<?php

namespace App\Services\Competition;

use App\Enums\PrizeMode;
use App\Enums\PrizePoolBasis;
use App\Enums\QuizPaperStatus;
use App\Models\Room;
use App\Services\Content\PaperValidator;

/**
 * Checks a draft room before it is published.
 */
class RoomConfigValidator
{
    public function __construct(protected PaperValidator $papers) {}

    /**
     * @return list<string> empty when the room can be published
     */
    public function problems(Room $room): array
    {
        $problems = [];
        $now = now();
        $lead = (int) config('competition.rooms.min_publish_lead_minutes');

        if (trim((string) $room->name) === '') {
            $problems[] = 'The room needs a title.';
        }

        $paper = $room->quizPaper()->first();
        if ($paper === null || $paper->status !== QuizPaperStatus::Published) {
            $problems[] = 'Choose a published quiz paper.';
        } else {
            foreach ($this->papers->problems($paper) as $problem) {
                $problems[] = 'Quiz paper: '.$problem;
            }
        }

        if ($room->category_id !== null && ! $room->category()->where('is_active', true)->exists()) {
            $problems[] = 'The selected category is inactive.';
        }

        if ($room->entry_fee_minor > 0 && ! config('competition.paid_entries.enabled')) {
            $problems[] = 'Paid entries are disabled, so the entry fee must be zero.';
        }

        if ($room->min_participants < 1 || $room->min_participants > $room->max_participants) {
            $problems[] = 'Minimum players must be between 1 and the maximum.';
        }

        if ($room->max_participants > (int) config('competition.rooms.max_capacity')) {
            $problems[] = 'Maximum players may not exceed '.config('competition.rooms.max_capacity').'.';
        }

        if ($room->type->hasScheduledStart()) {
            if ($room->scheduled_start_at === null) {
                $problems[] = 'Set a scheduled start time.';
            } elseif ($room->scheduled_start_at->lt($now->copy()->addMinutes($lead))) {
                $problems[] = "The scheduled start must be at least {$lead} minutes from now.";
            }

            if ($room->registration_closes_at && $room->scheduled_start_at && $room->registration_closes_at->gt($room->scheduled_start_at)) {
                $problems[] = 'Registration must close no later than the scheduled start.';
            }
        } else {
            if ($room->registration_closes_at === null) {
                $problems[] = 'Rooms that start when full need a registration deadline.';
            } elseif ($room->registration_closes_at->lt($now->copy()->addMinutes($lead))) {
                $problems[] = "The registration deadline must be at least {$lead} minutes from now.";
            }
        }

        $deadline = $room->registrationDeadline();
        if ($room->registration_opens_at && $deadline && $room->registration_opens_at->gte($deadline)) {
            $problems[] = 'Registration must open before it closes.';
        }

        $tiers = $room->prizeTiers()->orderBy('rank_from')->get();
        $expectedFrom = 1;
        foreach ($tiers as $tier) {
            if ($tier->rank_from !== $expectedFrom) {
                $problems[] = 'Prize ranks must start at 1 and continue without gaps or overlaps.';
                break;
            }
            if ($tier->rank_to > $room->max_participants) {
                $problems[] = "Prize rank {$tier->rank_to} is higher than the room capacity.";
            }
            $percentage = $room->prize_mode === PrizeMode::Percentage;
            if ($percentage ? ($tier->percent_bp ?? 0) <= 0 : $tier->amount_minor <= 0) {
                $problems[] = $percentage ? 'Every prize tier needs a positive percentage.' : 'Every prize tier needs a positive amount.';
            }
            $expectedFrom = $tier->rank_to + 1;
        }

        if ($room->prize_mode === PrizeMode::Percentage) {
            if ($tiers->sum(fn ($tier) => ($tier->percent_bp ?? 0) * $tier->positions()) > 10000) {
                $problems[] = 'Prize percentages add up to more than 100% of the pool.';
            }

            if ($room->prize_pool_basis === null) {
                $problems[] = 'Choose where the percentage prize pool comes from.';
            } elseif ($room->prize_pool_basis === PrizePoolBasis::Guaranteed && (int) $room->prize_pool_minor <= 0) {
                $problems[] = 'A guaranteed prize pool needs a positive amount.';
            } elseif ($room->prize_pool_basis === PrizePoolBasis::EntryFees && $room->entry_fee_minor <= 0) {
                $problems[] = 'A prize pool funded by entry fees needs an entry fee.';
            }

            if ($room->platform_fee_bp >= 10000) {
                $problems[] = 'The platform fee must be below 100%.';
            }
        }

        return array_values(array_unique($problems));
    }
}
