<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * A workflow as it comes off the builder: written, not yet switched on.
     *
     * The trigger is left as a bare key with an empty bag beside it, so a test
     * that cares about a particular trigger has to say so — a default that
     * quietly listened for messages would make half the suite depend on a
     * choice nobody made.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'enabled_at' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(['enabled_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function triggeredBy(string $type, array $config = []): static
    {
        return $this->state([
            'trigger_type' => $type,
            'trigger_config' => $config,
        ]);
    }

    /** A workflow whose author has since left. The runner refuses these. */
    public function ownerless(): static
    {
        return $this->state(['created_by' => null]);
    }
}
