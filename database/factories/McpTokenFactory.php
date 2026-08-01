<?php

namespace Database\Factories;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
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
            'token_hash' => McpToken::hashToken(fake()->unique()->uuid()),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }
}
