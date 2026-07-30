<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $channel = Channel::factory();

        return [
            'workspace_id' => fn (array $attributes) => Channel::find($attributes['channel_id'])?->workspace_id,
            'channel_id' => $channel,
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function inThread(Message $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'channel_id' => $parent->channel_id,
            'workspace_id' => $parent->workspace_id,
        ]);
    }
}
