<?php

namespace Database\Factories;

use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HuddleParticipant>
 */
class HuddleParticipantFactory extends Factory
{
    protected $model = HuddleParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'huddle_id' => Huddle::factory(),
            'user_id' => User::factory(),
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    /** Somebody who was in it and has since gone. */
    public function gone(): self
    {
        return $this->state(fn (): array => ['left_at' => now()]);
    }
}
