<?php

namespace Database\Factories;

use App\Models\BoardComment;
use App\Models\BoardPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardComment>
 */
class BoardCommentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'board_post_id' => BoardPost::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function on(BoardPost $post): static
    {
        return $this->state(['board_post_id' => $post->id]);
    }

    public function by(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
