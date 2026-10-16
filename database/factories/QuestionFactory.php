<?php

namespace Database\Factories;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'body' => rtrim(fake()->sentence(10), '.').'?',
            'explanation' => fake()->optional()->sentence(),
            'difficulty' => fake()->randomElement(QuestionDifficulty::cases()),
            'status' => QuestionStatus::Active,
            'default_time_limit_seconds' => null,
        ];
    }

    /**
     * Attach $count options, exactly one of them correct.
     */
    public function withOptions(int $count = 4): static
    {
        return $this->afterCreating(function (Question $question) use ($count) {
            $correct = fake()->numberBetween(1, $count);

            foreach (range(1, $count) as $position) {
                QuestionOption::factory()->for($question)->create([
                    'position' => $position,
                    'is_correct' => $position === $correct,
                ]);
            }
        });
    }
}
