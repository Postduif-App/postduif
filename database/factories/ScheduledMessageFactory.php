<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledMessage>
 */
class ScheduledMessageFactory extends Factory
{
    protected $model = ScheduledMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'send_at' => now()->addHour(),
        ];
    }

    /** Already past its moment, for testing the dispatcher. */
    public function due(): static
    {
        return $this->state(fn (): array => ['send_at' => now()->subMinute()]);
    }
}
