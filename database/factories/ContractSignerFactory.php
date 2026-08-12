<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSigner>
 */
class ContractSignerFactory extends Factory
{
    /**
     * Somebody from outside, first in the queue, who has not been round yet.
     *
     * Outside rather than a colleague, because that is the case the feature is
     * built for and the one where nothing but the token stands between the
     * person and the document.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'token' => ContractSigner::freshToken(),
            'signing_order' => 0,
        ];
    }

    /** A colleague picked from the workspace rather than an address typed in. */
    public function forUser(User $user): static
    {
        return $this->state([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    public function inPosition(int $order): static
    {
        return $this->state(['signing_order' => $order]);
    }

    public function opened(): static
    {
        return $this->state(['opened_at' => now()->subHour()]);
    }

    /** Signed, with the two facts the audit trail keeps about the browser. */
    public function signed(): static
    {
        return $this->state([
            'opened_at' => now()->subHour(),
            'signed_at' => now()->subMinutes(30),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);
    }

    /** Read it and said no, which is an outcome rather than a failure. */
    public function declined(?string $reason = null): static
    {
        return $this->state([
            'opened_at' => now()->subHour(),
            'declined_at' => now()->subMinutes(30),
            'decline_reason' => $reason ?? 'Niet akkoord met artikel 4.',
        ]);
    }
}
