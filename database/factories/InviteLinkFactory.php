<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InviteLink>
 */
class InviteLinkFactory extends Factory
{
    /**
     * The ordinary case: a member link, good for a fortnight, with no ceiling
     * on how often it is used.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'token' => InviteLink::freshToken(),
            'role' => SystemRole::Member,
            'max_uses' => null,
            'expires_at' => now()->addDays(14),
            'uses' => 0,
        ];
    }

    /** An external participant, who only gets the channels on the link. */
    public function guest(): static
    {
        return $this->state(['role' => SystemRole::Guest]);
    }

    /** No ceiling and no date: an open door until somebody withdraws it. */
    public function unlimited(): static
    {
        return $this->state(['max_uses' => null, 'expires_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }

    /** As many uses as it was allowed, so the next person is turned away. */
    public function exhausted(int $max = 3): static
    {
        return $this->state(['max_uses' => $max, 'uses' => $max]);
    }
}
