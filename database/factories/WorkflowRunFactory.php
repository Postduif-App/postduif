<?php

namespace Database\Factories;

use App\Enums\WorkflowRunStatus;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'status' => WorkflowRunStatus::Running,
            'context' => [],
            'resume_position' => 0,
        ];
    }

    /**
     * A run that is biding its time. Past by default, so it is due — a test
     * that wants one still waiting says so with a moment of its own.
     */
    public function waiting(?string $until = null): static
    {
        return $this->state([
            'status' => WorkflowRunStatus::Waiting,
            'resume_at' => $until !== null ? now()->parse($until) : now()->subMinute(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state([
            'status' => WorkflowRunStatus::Succeeded,
            'finished_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Er ging iets mis.'): static
    {
        return $this->state([
            'status' => WorkflowRunStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
