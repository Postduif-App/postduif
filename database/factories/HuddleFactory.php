<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Huddle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Huddle>
 */
class HuddleFactory extends Factory
{
    protected $model = Huddle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'started_by' => User::factory(),
            'ended_at' => null,
        ];
    }

    /** One that is over. */
    public function ended(): self
    {
        return $this->state(fn (): array => ['ended_at' => now()->subMinutes(5)]);
    }
}
