<?php

namespace Database\Factories;

use App\Enums\WorkflowBranch;
use App\Enums\WorkflowStepKind;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'position' => 0,
            'action_type' => 'send-channel-message',
            'config' => [],
            'condition' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function doing(string $actionType, array $config = []): static
    {
        return $this->state([
            'action_type' => $actionType,
            'config' => $config,
        ]);
    }

    public function at(int $position): static
    {
        return $this->state(['position' => $position]);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public function onlyIf(array $condition): static
    {
        return $this->state(['condition' => $condition]);
    }

    /**
     * A fork rather than a step: it does nothing and picks a lane.
     *
     * The action_type carries the kind's own name, which is what the controller
     * writes too — the column is not nullable and a fork does not do any of the
     * things the register knows.
     */
    public function forking(): static
    {
        return $this->state([
            'kind' => WorkflowStepKind::Branch,
            'action_type' => WorkflowStepKind::Branch->value,
        ]);
    }

    /** In one of a fork's two lanes. */
    public function inLane(WorkflowStep $fork, WorkflowBranch $branch): static
    {
        return $this->state([
            'parent_step_id' => $fork->id,
            'branch' => $branch,
        ]);
    }
}
