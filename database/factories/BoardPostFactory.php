<?php

namespace Database\Factories;

use App\Models\BoardPost;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardPost>
 */
class BoardPostFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'title' => 'Kerstborrel op 20 december',
            'body' => fake()->paragraph(),
        ];
    }

    public function in(Workspace $workspace): static
    {
        return $this->state(['workspace_id' => $workspace->id]);
    }

    public function by(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    /** At the top of the board, above everything not pinned. */
    public function pinned(): static
    {
        return $this->state(['pinned_at' => now()]);
    }
}
