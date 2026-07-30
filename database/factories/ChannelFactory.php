<?php

namespace Database\Factories;

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
        $name = Str::slug(fake()->unique()->words(2, true));

        return [
            'workspace_id' => Workspace::factory(),
            'type' => ChannelType::Public,
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
