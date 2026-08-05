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
     * Fill in the role once the workspace behind it exists.
     *
     * A role is a row of a particular workspace, so it cannot be resolved while
     * the workspace is still a factory. afterMaking runs when the attributes
     * have been settled and the parent has been made, which is the first moment
     * there is a workspace to ask.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (InviteLink $row): void {
            $row->workspace_role_id ??= roleIdFor($row->workspace_id, SystemRole::Member);
        });
    }

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
            'max_uses' => null,
            'expires_at' => now()->addDays(14),
            'uses' => 0,
        ];
    }

    /** An external participant, who only gets the channels on the link. */
    public function guest(): static
    {
        return $this->afterMaking(fn (InviteLink $row) => $row->workspace_role_id = roleIdFor($row->workspace_id, SystemRole::Guest));
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
