<?php

namespace App\Models;

use App\Enums\WorkflowBranch;
use App\Enums\WorkflowStepStatus;
use Database\Factories\WorkflowStepRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What became of one step, one time.
 *
 * The position and the action are copied here rather than read back through the
 * step, on purpose: somebody who reorders a workflow after a run has gone wrong
 * should not thereby rewrite what the run says it did.
 *
 * @property int $id
 * @property int $workflow_run_id
 * @property int|null $workflow_step_id
 * @property int $position
 * @property string $action_type
 * @property WorkflowBranch|null $branch
 * @property WorkflowStepStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workflow_step_id', 'position', 'action_type', 'branch', 'status', 'result', 'failure_reason'])]
class WorkflowStepRun extends Model
{
    /** @use HasFactory<WorkflowStepRunFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'branch' => WorkflowBranch::class,
            'status' => WorkflowStepStatus::class,
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }
}
