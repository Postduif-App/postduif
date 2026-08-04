<?php

namespace App\Models;

use App\Enums\WorkflowRunStatus;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One time a workflow was walked through.
 *
 * Two jobs in one row. It is the record somebody reads afterwards to find out
 * why nothing happened, and it is the place a workflow that is waiting keeps
 * its position — which is why the context is stored rather than carried in
 * memory: an hour later there is no memory left to carry it in.
 *
 * @property int $id
 * @property int $workflow_id
 * @property WorkflowRunStatus $status
 * @property array<string, mixed> $context
 * @property int $resume_position
 * @property list<int>|null $resume_plan
 * @property Carbon|null $resume_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workflow_id', 'status', 'context', 'resume_position'])]
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => WorkflowRunStatus::Running->value,
        'context' => '{}',
        'resume_position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WorkflowRunStatus::class,
            'context' => 'array',
            'resume_position' => 'integer',
            'resume_plan' => 'array',
            'resume_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * What this run did, in the order it did it.
     *
     * By id rather than by position. A run does not visit the positions in
     * order once a workflow forks — it skips the lane it did not take — and a
     * run that waited an hour and carried on appends rather than fills in.
     * Chronological is what somebody reading a run wants anyway.
     *
     * @return HasMany<WorkflowStepRun, $this>
     */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class)->orderBy('id');
    }

    /**
     * Put something in the run's memory under a name, and keep it.
     *
     * Written through immediately rather than at the end of the run. A run that
     * dies halfway is exactly the one somebody will want to read, and a context
     * that only reached the database on success would be empty for every run
     * worth looking at.
     */
    public function remember(string $key, mixed $value): void
    {
        $context = $this->context;

        data_set($context, $key, $value);

        $this->forceFill(['context' => $context])->save();
    }

    /**
     * The runs whose waiting is over.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', WorkflowRunStatus::Waiting)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now());
    }
}
