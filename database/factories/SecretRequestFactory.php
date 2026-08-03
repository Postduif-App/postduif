<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\SecretRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecretRequest>
 */
class SecretRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'title' => 'Omgevingsvariabelen voor de staging-server',
            'description' => null,
            'expires_at' => now()->addWeek(),
            'burn_after_reading' => false,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }

    /** The value goes the moment the requester has read it. */
    public function burnAfterReading(): static
    {
        return $this->state(['burn_after_reading' => true]);
    }
}
