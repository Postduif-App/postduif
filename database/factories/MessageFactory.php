<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use DateTimeInterface;
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
        return [
            // Before workspace_id, not after: attributes are expanded in the
            // order they are declared, so the closure below only sees a channel
            // id once this line has already resolved its factory.
            'channel_id' => Channel::factory(),
            'workspace_id' => fn (array $attributes) => Channel::find((int) $attributes['channel_id'])?->workspace_id,
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A message posted through a webhook: no member behind it, a bot name in
     * place of one.
     */
    public function fromBot(?Webhook $webhook = null): static
    {
        if ($webhook === null) {
            return $this->state(fn (array $attributes) => [
                'user_id' => null,
                'webhook_id' => Webhook::factory()->state([
                    'channel_id' => $attributes['channel_id'],
                ]),
                'bot_name' => fake()->firstName().'bot',
            ]);
        }

        return $this->state([
            'user_id' => null,
            'webhook_id' => $webhook->id,
            'bot_name' => $webhook->bot_name,
            'channel_id' => $webhook->channel_id,
            'workspace_id' => $webhook->workspace_id,
        ]);
    }

    /**
     * A root message that already carries thread activity.
     *
     * Sets the counters SendMessage would have bumped, so a test can place a
     * thread's last reply at an arbitrary moment without creating replies at
     * that moment — which is the only way to test the "x hours" window.
     */
    public function withThreadActivity(DateTimeInterface $lastReplyAt, int $replyCount = 1): static
    {
        return $this->state([
            'parent_id' => null,
            'reply_count' => $replyCount,
            'last_reply_at' => $lastReplyAt,
        ]);
    }

    /**
     * A message that is already pinned to its channel.
     */
    public function pinned(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pinned_at' => now(),
            // $by is either a user or absent; when it is there it has an id,
            // so the fallback belongs to the argument, not to the property.
            'pinned_by' => $by !== null
                ? $by->id
                : ($attributes['user_id'] ?? User::factory()),
        ]);
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
