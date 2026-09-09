<?php

namespace Database\Factories;

use App\Models\BacklogConnection;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BacklogConnection>
 */
class BacklogConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Declared before workspace_id so the closure below is handed a
            // resolved channel id rather than an unexpanded factory — the same
            // ordering WebhookFactory relies on.
            'channel_id' => Channel::factory(),
            'workspace_id' => fn (array $attributes) => Channel::find((int) $attributes['channel_id'])?->workspace_id,

            // An address, not a name — the same reason ContractWebhookFactory
            // points at one: a test that hits DNS is a test that fails on a
            // train.
            'backlog_url' => 'https://93.184.216.34',

            'client_id' => 'blg_'.Str::random(24),
            'client_secret' => 'bls_'.Str::random(48),
            'webhook_secret' => 'whs_'.Str::random(48),

            'events' => BacklogConnection::EVENTS,
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    /**
     * Listening for one kind of news and no other.
     *
     * @param  list<string>  $events
     */
    public function forEvents(array $events): static
    {
        return $this->state(['events' => $events]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function unhealthy(): static
    {
        return $this->state(['consecutive_failures' => BacklogConnection::FAILURE_LIMIT]);
    }
}
