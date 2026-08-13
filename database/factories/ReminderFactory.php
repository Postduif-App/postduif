<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message_id' => Message::factory(),
            'channel_id' => Channel::factory(),
            // In the future, which is what a reminder is. A test that wants one
            // that has come round says so, in due() below.
            'remind_at' => now()->addHour(),
        ];
    }

    /** One whose moment has passed and that the sweep has not reached yet. */
    public function due(): self
    {
        return $this->state(fn (): array => ['remind_at' => now()->subMinute()]);
    }

    /** One that has already gone off. */
    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'remind_at' => now()->subHour(),
            'delivered_at' => now()->subHour(),
        ]);
    }
}
