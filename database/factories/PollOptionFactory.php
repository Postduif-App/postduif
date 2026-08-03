<?php

namespace Database\Factories;

use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollOption>
 */
class PollOptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'label' => fake()->unique()->word(),
            'position' => 0,
        ];
    }
}
