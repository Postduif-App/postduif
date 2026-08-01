<?php

namespace Database\Factories;

use App\Models\ChannelSection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelSection>
 */
class ChannelSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->word(),
            'position' => 0,
        ];
    }
}
