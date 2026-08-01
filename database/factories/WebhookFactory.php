<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Declared before workspace_id so the closure below is handed a
            // resolved channel id rather than an unexpanded factory.
            'channel_id' => Channel::factory(),
            'workspace_id' => fn (array $attributes) => Channel::find((int) $attributes['channel_id'])?->workspace_id,
            'name' => fake()->words(2, true),
            'bot_name' => fake()->firstName().'bot',
            // A hash of something no test knows, so a webhook is unusable until
            // a test deliberately mints a token for it.
            'token_hash' => Webhook::hashToken(Str::random(48)),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
