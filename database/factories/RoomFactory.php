<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\QuizPaper;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rooms are free (entry_fee_minor = 0) unless a state says otherwise.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Challenge',
            'description' => fake()->optional()->sentence(),
            'quiz_paper_id' => QuizPaper::factory()->published(),
            'type' => RoomType::Scheduled,
            'status' => RoomStatus::Draft,
            'entry_fee_minor' => 0,
            'currency' => 'PKR',
            'min_participants' => 2,
            'max_participants' => 50,
            'scheduled_start_at' => now()->addDay()->startOfHour(),
        ];
    }

    public function status(RoomStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoomStatus::Open,
            'published_at' => now(),
            'registration_opens_at' => now()->subHour(),
        ]);
    }

    public function fill(int $capacity = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => RoomType::Fill,
            'min_participants' => $capacity,
            'max_participants' => $capacity,
            'scheduled_start_at' => null,
        ]);
    }

    /**
     * Configures a fee only; no money moves. Paid entry stays blocked by
     * config('competition.paid_entries.enabled') in the entry service.
     */
    public function withEntryFee(int $amountMinor): static
    {
        return $this->state(fn (array $attributes) => [
            'entry_fee_minor' => $amountMinor,
        ]);
    }
}
