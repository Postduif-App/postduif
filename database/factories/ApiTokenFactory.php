<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Claude op '.fake()->word(),
            // Overwritten by regenerateToken() wherever a usable token is
            // wanted; a placeholder keeps the unique index happy meanwhile.
            'token_hash' => ApiToken::hashToken(fake()->unique()->uuid()),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }
}
