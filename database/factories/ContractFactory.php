<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * The ordinary case: a draft of three pages, no deadline yet.
     *
     * No PDF on it. Attaching one would mean running Ghostscript for every test
     * that happens to need a contract row, which is seconds apiece for something
     * most of them never look at — see withSource() for the tests that do.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'title' => fake()->words(3, true),
            'message' => null,
            'status' => ContractStatus::Draft,
            'page_count' => 3,
            'source_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => null,
        ];
    }

    /** Out with the signers, with a fortnight to answer. */
    public function sent(): static
    {
        return $this->state([
            'status' => ContractStatus::Sent,
            'expires_at' => now()->addWeeks(2),
        ]);
    }

    /** Everybody has been round. The one state the prune command may not touch. */
    public function completed(): static
    {
        return $this->state([
            'status' => ContractStatus::Completed,
            'completed_at' => now()->subDay(),
        ]);
    }

    /**
     * Stopped by the author.
     *
     * The stamp is what the grace period is counted from, so a test about
     * pruning wants to say how long ago: cancelled(now()->subMonths(2)).
     */
    public function cancelled(?\DateTimeInterface $at = null): static
    {
        return $this->state([
            'status' => ContractStatus::Cancelled,
            'cancelled_at' => $at ?? now()->subDay(),
        ]);
    }

    /** Sent, and the deadline has come and gone without the column noticing. */
    public function overdue(?\DateTimeInterface $at = null): static
    {
        return $this->state([
            'status' => ContractStatus::Sent,
            'expires_at' => $at ?? now()->subDay(),
        ]);
    }

    /** Already marked expired by the prune command. */
    public function expired(?\DateTimeInterface $at = null): static
    {
        return $this->state([
            'status' => ContractStatus::Expired,
            'expires_at' => $at ?? now()->subDay(),
        ]);
    }
}
