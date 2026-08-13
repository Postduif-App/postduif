<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ScheduledHuddle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledHuddle>
 */
class ScheduledHuddleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'title' => 'Stand-up',
            // In the diary, which is what an appointment is. A test that wants
            // one whose moment has come says so, in due() below.
            'starts_at' => now()->addHour(),
            'duration_minutes' => 30,
        ];
    }

    /** One whose moment has passed and that has not been announced yet. */
    public function due(): self
    {
        return $this->state(fn (): array => ['starts_at' => now()->subMinute()]);
    }

    /** One the channel has already been told about. */
    public function announced(): self
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMinutes(5),
            'announced_at' => now()->subMinutes(5),
        ]);
    }

    /** One that was called off. */
    public function cancelled(): self
    {
        return $this->state(fn (): array => ['cancelled_at' => now()]);
    }
}
