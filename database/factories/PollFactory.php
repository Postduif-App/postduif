<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Poll;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'question' => 'Wanneer doen we de retro?',
            'allows_multiple' => false,
            'closes_at' => null,
        ];
    }

    /** Somebody stopped it by hand. */
    public function closed(): static
    {
        return $this->state(['closed_at' => now()->subHour()]);
    }

    /** Its moment simply passed — a different thing, and the card says so. */
    public function expired(): static
    {
        return $this->state(['closes_at' => now()->subHour()]);
    }

    public function multipleChoice(): static
    {
        return $this->state(['allows_multiple' => true]);
    }
}
