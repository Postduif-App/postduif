<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token' => Invitation::freshToken(),
            'expires_at' => now()->addDays(Invitation::VALID_FOR_DAYS),
        ];
    }

    /**
     * An external participant, who only gets the channels attached to the
     * invitation itself.
     */
    public function guest(): static
    {
        return $this->state(['role' => WorkspaceRole::Guest]);
    }

    /**
     * A link that is past its date and should no longer let anybody in.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()->subHour()]);
    }
}
