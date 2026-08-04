<?php

namespace Database\Factories;

use App\Enums\WorkflowStepStatus;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepRun>
 */
class WorkflowStepRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'workflow_step_id' => null,
            'position' => 0,
            'action_type' => 'send-channel-message',
            'status' => WorkflowStepStatus::Succeeded,
            'result' => null,
        ];
    }

    public function skipped(): static
    {
        return $this->state(['status' => WorkflowStepStatus::Skipped]);
    }

    public function failed(string $reason = 'Er ging iets mis.'): static
    {
        return $this->state([
            'status' => WorkflowStepStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
