<?php

namespace App\Models;

use App\Enums\WorkflowBranch;
use App\Enums\WorkflowStepKind;
use Database\Factories\WorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thing a workflow does, and when it should be skipped.
 *
 * The configuration is a bag rather than columns because every action asks for
 * something different: a channel here, a piece of text there, a number of
 * minutes for the one that waits. What keeps that from being a free-for-all is
 * the register — the action itself says which keys it expects, and the builder
 * draws its form from that same answer.
 *
 * A step of the other kind does nothing and has no configuration: it reads its
 * condition and hands the run to one of its two lanes. Its children are steps
 * like any other, which is what keeps the runner from needing a second idea of
 * what a step is.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $position
 * @property WorkflowStepKind $kind
 * @property int|null $parent_step_id
 * @property WorkflowBranch|null $branch
 * @property string $action_type
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $condition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['position', 'kind', 'parent_step_id', 'branch', 'action_type', 'config', 'condition'])]
class WorkflowStep extends Model
{
    /** @use HasFactory<WorkflowStepFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'config' => '{}',
        'position' => 0,
        'kind' => WorkflowStepKind::Action->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => WorkflowStepKind::class,
            'branch' => WorkflowBranch::class,
            'config' => 'array',
            'condition' => 'array',
        ];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function parentStep(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_step_id');
    }

    /**
     * Both lanes at once, in the order they run.
     *
     * By branch first and position second, so that a fork's children come back
     * as "everything in the then lane, then everything in the else lane" —
     * which is the order the builder draws them in and the order anything
     * grouping them wants them in.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_step_id')
            ->orderBy('branch')
            ->orderBy('position');
    }

    public function isBranch(): bool
    {
        return $this->kind === WorkflowStepKind::Branch;
    }

    /**
     * The steps at the top of a workflow: the ones that hang under no fork.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAtTheTop(Builder $query): void
    {
        $query->whereNull('parent_step_id');
    }

    /**
     * The steps in one lane of this fork, in order.
     *
     * @return Collection<int, WorkflowStep>
     */
    public function lane(WorkflowBranch $branch)
    {
        return $this->children()
            ->where('branch', $branch)
            ->reorder('position')
            ->get();
    }

    /**
     * Whether anything at all stands between this step and running.
     *
     * Null, an empty array and a condition with no rules left in it all mean
     * no: those are what a form leaves behind when somebody opened the panel
     * and closed it again, and treating them as a condition that is never met
     * would silence the step for a reason nobody could see.
     *
     * The bare `path` is the shape the first version of this feature saved, and
     * still counts — see EvaluateCondition, which reads it the same way.
     */
    public function hasCondition(): bool
    {
        if ($this->condition === null || $this->condition === []) {
            return false;
        }

        if (isset($this->condition['rules'])) {
            return is_array($this->condition['rules']) && $this->condition['rules'] !== [];
        }

        return isset($this->condition['path']);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
