<?php

namespace Database\Factories;

use App\Enums\QuizPaperStatus;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizPaper>
 */
class QuizPaperFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'status' => QuizPaperStatus::Draft,
            'default_time_limit_seconds' => 20,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuizPaperStatus::Published,
        ]);
    }

    /**
     * Create $count new questions (with options) and attach them in order.
     */
    public function withQuestions(int $count = 5): static
    {
        return $this->afterCreating(function (QuizPaper $paper) use ($count) {
            foreach (range(1, $count) as $position) {
                QuizPaperQuestion::create([
                    'quiz_paper_id' => $paper->id,
                    'question_id' => Question::factory()->withOptions()->create()->id,
                    'position' => $position,
                ]);
            }
        });
    }
}
