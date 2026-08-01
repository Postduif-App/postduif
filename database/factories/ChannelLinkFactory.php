<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelLink>
 */
class ChannelLinkFactory extends Factory
{
    protected $model = ChannelLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'label' => fake()->words(2, true),
            'url' => fake()->url(),
            'position' => 0,
        ];
    }
}
