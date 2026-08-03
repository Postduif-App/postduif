<?php

namespace Database\Factories;

use App\Enums\TransferAudience;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    /**
     * The ordinary case: something sent off for a week, with no ceiling on how
     * often it is fetched.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'token' => Transfer::freshToken(),
            'audience' => TransferAudience::Everyone,
            'title' => fake()->words(3, true),
            'message' => null,
            'expires_at' => now()->addWeek(),
            'max_downloads' => null,
            'downloads' => 0,
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

    /** Fetched as often as it was allowed, so the next person is turned away. */
    public function exhausted(int $max = 3): static
    {
        return $this->state(['max_downloads' => $max, 'downloads' => $max]);
    }

    /** Only reachable by somebody signed in and in the workspace. */
    public function membersOnly(): static
    {
        return $this->state(['audience' => TransferAudience::WorkspaceMembers]);
    }

    /** With something the recipient has to know as well as hold. */
    public function locked(string $password = 'geheim123'): static
    {
        return $this->state(['password' => Hash::make($password)]);
    }

    /** One fetch and no more — the strictest a transfer gets. */
    public function once(): static
    {
        return $this->state(['max_downloads' => 1]);
    }
}
