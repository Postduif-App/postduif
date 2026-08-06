<?php

namespace Database\Factories;

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Two word() calls rather than words(2, true): the plural one is
        // declared as returning either a list or a string depending on a flag,
        // which is not something a type can follow.
        $name = Str::slug(fake()->unique()->word().' '.fake()->word());

        return [
            'workspace_id' => Workspace::factory(),
            'type' => ChannelType::Public,
            // Spelled out rather than left to the column default, which the
            // database only fills in on the way in: the model handed back by
            // create() would carry null, and Channel types this as an enum and
            // reads it without asking.
            'posting_policy' => ChannelPostingPolicy::Everyone,
            'name' => $name,
            'slug' => $name,
            'topic' => fake()->optional()->sentence(),
        ];
    }

    public function private(): static
    {
        return $this->state(['type' => ChannelType::Private]);
    }

    /**
     * DMs carry no name or slug; the UI labels them by their members.
     */
    public function direct(): static
    {
        return $this->state([
            'type' => ChannelType::Direct,
            'name' => null,
            'slug' => null,
            'topic' => null,
        ]);
    }
}
