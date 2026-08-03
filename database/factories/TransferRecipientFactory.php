<?php

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferRecipient>
 */
class TransferRecipientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transfer_id' => Transfer::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => TransferRecipient::freshToken(),
            'downloads' => 0,
        ];
    }

    /** Stopped on its own, while the others on the same transfer carry on. */
    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }
}
