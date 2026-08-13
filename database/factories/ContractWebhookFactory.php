<?php

namespace Database\Factories;

use App\Models\ContractWebhook;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContractWebhook>
 */
class ContractWebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(2, true),

            /*
             * An address rather than a name, because GuardOutboundUrl resolves
             * what it is given and a test that hits DNS is a test that fails on
             * a train. The same reasoning — and the same address — as the
             * workflow HTTP suite.
             */
            'url' => 'https://93.184.216.34/hooks/'.fake()->slug(2),

            'secret' => 'whs_'.Str::random(48),

            // Everything, because a test that cares about which events a
            // subscription wants says so itself.
            'events' => ContractWebhook::EVENTS,
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

    public function disabled(): static
    {
        return $this->state(['disabled_at' => now()]);
    }
}
