<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\EphemeralNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EphemeralNotice>
 */
class EphemeralNoticeFactory extends Factory
{
    protected $model = EphemeralNotice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'author_name' => null,
            'expires_at' => null,
        ];
    }

    /** One that has already stopped being worth showing. */
    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
