<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowBranch;
use App\Enums\WorkflowConditionOutcome;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\EvaluateCondition;
use App\Workflows\ResolveVariables;
use App\Workflows\WorkflowDepth;
use App\Workflows\WorkflowPaused;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walk a run through its steps.
 *
 * Everything a run does happens here, one step at a time and in order, with a
 * line written for each — including the ones that did nothing. A step that
 * fails stops the run: carrying on would mean running steps that were written
 * on the assumption the previous one worked, and "added to a channel that the
 * next step never created" is worse than stopping.
 *
 * Starts at whatever the run says is left rather than at the beginning, which
 * is what makes a workflow that waited an hour able to pick up where it stood
 * without a second way of walking the same shape.
 */
class RunWorkflow
{
    /**
     * A ceiling on how many steps one run may take.
     *
     * Far above any real workflow — a workflow may hold 25 steps — so the only
     * thing this can catch is a shape that leads back into itself. It cannot be
     * written on the builder screen and the controller does not save one, which
     * is precisely why the runner should not be the place it spins forever.
     */
    private const MAX_STEPS_PER_RUN = 250;

    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly ResolveVariables $variables,
        private readonly EvaluateCondition $condition,
    ) {}

    public function handle(WorkflowRun $run): void
    {
        if (! $run->status->isOpen()) {
            return;
        }

        $workflow = $run->workflow;

        /*
         * Checked again here and not only at the start. A run may have sat on
         * the queue, or waited an hour, and in that time the workflow can be
         * switched off or its author's account can be gone — both of which mean
         * the remaining steps should not run with rights nobody has any more.
         */
        if ($workflow === null || ! $workflow->isEnabled() || $workflow->owner === null) {
            $this->giveUp($run, __('workflows.run.no_longer_allowed'));

            return;
        }

        $run->forceFill(['status' => WorkflowRunStatus::Running])->save();

        /*
         * The depth the run was started at, restored for the length of this
         * call. Anything these steps do that would set another workflow off —
         * a message posted, an emoji placed — passes through StartWorkflow,
         * which asks the counter whether there is still room.
         */
        WorkflowDepth::within((int) data_get($run->context, 'depth', 1), function () use ($run): void {
            $this->walk($run, $this->plan($run));
        });
    }

    /**
     * Work through what is left of a run, one step at a time.
     *
     * A queue rather than a loop over rows, because a workflow is a shape: a
     * fork does not run its lanes, it puts one of them at the front of what is
     * still to come. That also makes "where a run stands" something that can be
     * written down — see pause(), which stores exactly what is left here.
     *
     * @param  list<int>  $queue
     */
    private function walk(WorkflowRun $run, array $queue): void
    {
        $taken = 0;

        while ($queue !== []) {
            /*
             * A workflow may hold 25 steps, so a run that has taken many times
             * that is not a long workflow — it is a fork that somehow points at
             * itself. Said out loud rather than left to spin: the shape is
             * written as a tree and this cannot happen, which is exactly why it
             * should be loud if it ever does.
             */
            if (++$taken > self::MAX_STEPS_PER_RUN) {
                $this->giveUp($run, __('workflows.run.went_round_in_circles'));

                return;
            }

            $step = WorkflowStep::find(array_shift($queue));

            /*
             * A step that went while the run was waiting, or one that belongs
             * to another workflow altogether. Passed over rather than failed: a
             * run should not fail over a step somebody deliberately removed.
             */
            if ($step === null || $step->workflow_id !== $run->workflow_id) {
                continue;
            }

            if ($step->isBranch()) {
                $queue = [...$this->chooseLane($run, $step), ...$queue];

                continue;
            }

            if (! $this->perform($run, $step, $queue)) {
                return;
            }
        }

        $this->finish($run);
    }

    /**
     * Read a fork and hand back the lane it chose.
     *
     * The line it writes says which way it went, because that is the question
     * somebody brings to a run that did half of what they expected — and the
     * lane it did not take leaves no trace at all, which is the honest record:
     * those steps were never at the door.
     *
     * @return list<int>
     */
    private function chooseLane(WorkflowRun $run, WorkflowStep $step): array
    {
        $lane = $this->condition->passes($step->condition, $run->context)
            ? WorkflowBranch::Then
            : WorkflowBranch::Else;

        $this->record($run, $step, WorkflowStepStatus::Succeeded, result: ['lane' => $lane->value]);

        return array_values(array_map(intval(...), $step->lane($lane)->pluck('id')->all()));
    }

    /**
     * What a run still has ahead of it.
     *
     * A waiting run carries its own answer — see pause(). Anything else starts
     * at the top of the workflow, which is read fresh: the workflow as it
     * stands now is the one the workspace means to be running.
     *
     * @return list<int>
     */
    private function plan(WorkflowRun $run): array
    {
        if (is_array($run->resume_plan)) {
            return $run->resume_plan;
        }

        /*
         * resume_position is what a run that was waiting when this application
         * had no forks yet is holding. Honoured here so those runs pick up
         * where they left off rather than start over, and written by nothing
         * any more.
         */
        return array_values(array_map(intval(...), $run->workflow->topSteps()
            ->where('position', '>=', $run->resume_position)
            ->pluck('id')
            ->all()));
    }

    /**
     * One step. Returns whether the run may go on.
     *
     * What is still queued behind it comes along, because a step that waits has
     * to leave that behind for the run that picks this up in an hour.
     *
     * @param  list<int>  $remaining
     */
    private function perform(WorkflowRun $run, WorkflowStep $step, array $remaining): bool
    {
        $action = $this->registry->resolveAction($step->action_type);

        /*
         * An action the register does not know. That is not a broken step so
         * much as a broken workflow — most likely a class that was taken out
         * while workflows still pointed at it — so it is said plainly rather
         * than skipped, which would leave somebody wondering why the message
         * never arrived.
         */
        if ($action === null) {
            $this->record($run, $step, WorkflowStepStatus::Failed, failure: __('workflows.run.unknown_action', [
                'action' => $step->action_type,
            ]));

            $this->giveUp($run, __('workflows.run.unknown_action', ['action' => $step->action_type]));

            return false;
        }

        /*
         * Asked before anything is resolved or run. A step that is not going to
         * happen should not have its variables filled in either — that work is
         * wasted, and worse, a resolved value would end up on the skipped
         * step's line as though something had been prepared for it.
         */
        if ($step->hasCondition() && ! $this->condition->passes($step->condition, $run->context)) {
            $this->record($run, $step, WorkflowStepStatus::Skipped);

            /*
             * A condition that guards the rest of the workflow rather than one
             * step. The line is still written and still says Skipped — what
             * happened to this step is what happened to it, and the run's own
             * status is where "and nothing after it either" is recorded.
             */
            if ($this->condition->outcome($step->condition) === WorkflowConditionOutcome::Stop) {
                $this->stop($run);

                return false;
            }

            return true;
        }

        try {
            $result = $action->run(new WorkflowStepContext(
                workflow: $run->workflow,
                run: $run,
                /*
                 * Every {{ ... }} already replaced, so no action has to know
                 * that variables exist. What an action reads is a plain value.
                 */
                config: $this->variables->handle($step->config, $action::fields(), $run->context),
            ));
        } catch (WorkflowPaused $paused) {
            /*
             * Not a failure, so nothing is recorded against the step and the
             * run keeps its open status. What is left is what was queued behind
             * this step and not the step itself: the waiting was what it was
             * for, and coming back to it would wait again, forever.
             */
            $this->pause($run, $step, $remaining, $paused);

            return false;
        } catch (Throwable $exception) {
            /*
             * Logged as well as recorded. The run screen is where somebody
             * looks, but it only carries the sentence — a workflow failing on
             * something the application itself got wrong deserves a stack trace
             * somewhere a developer will find it.
             */
            Log::warning('Een workflowstap ging mis.', [
                'workflow_run_id' => $run->id,
                'workflow_step_id' => $step->id,
                'exception' => $exception,
            ]);

            $reason = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('workflows.run.step_failed');

            $this->record($run, $step, WorkflowStepStatus::Failed, failure: $reason);
            $this->giveUp($run, $reason);

            return false;
        }

        $this->record($run, $step, WorkflowStepStatus::Succeeded, result: $result);

        /*
         * Filed under the step's position, so a later step reads
         * {{ steps.2.channel.id }}. By position rather than by name because a
         * step has no name — see the builder, where they are a row.
         */
        if ($result !== null) {
            $run->remember("steps.{$step->position}", $result);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function record(
        WorkflowRun $run,
        WorkflowStep $step,
        WorkflowStepStatus $status,
        ?array $result = null,
        ?string $failure = null,
    ): void {
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id,
            'position' => $step->position,
            'action_type' => $step->action_type,
            'branch' => $step->branch,
            'status' => $status,
            'result' => $result,
            'failure_reason' => $failure,
        ]);
    }

    /**
     * Put the run down until its moment comes.
     *
     * The step run gets a line of its own, marked as having done what it was
     * for: the run screen otherwise shows a gap where the waiting was, and
     * "nothing between these two steps" is exactly the thing somebody would be
     * trying to understand.
     *
     * @param  list<int>  $remaining
     */
    private function pause(WorkflowRun $run, WorkflowStep $step, array $remaining, WorkflowPaused $paused): void
    {
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id,
            'position' => $step->position,
            'action_type' => $step->action_type,
            'branch' => $step->branch,
            'status' => WorkflowStepStatus::Succeeded,
            'result' => ['until' => $paused->until->toIso8601String()],
        ]);

        $run->forceFill([
            'status' => WorkflowRunStatus::Waiting,
            'resume_plan' => $remaining,
            'resume_at' => $paused->until,
        ])->save();
    }

    /**
     * Put the run down because a condition said the rest is not to happen.
     *
     * No failure reason, because nothing went wrong. The step runs already show
     * where it stopped and which condition was the last thing read, and a
     * sentence saying "a condition said no" would only repeat the line above
     * it.
     */
    private function stop(WorkflowRun $run): void
    {
        $run->forceFill([
            'status' => WorkflowRunStatus::Stopped,
            'finished_at' => now(),
            'resume_at' => null,
            'resume_plan' => null,
        ])->save();
    }

    private function finish(WorkflowRun $run): void
    {
        $run->forceFill([
            'status' => WorkflowRunStatus::Succeeded,
            'finished_at' => now(),
            'resume_at' => null,
            'resume_plan' => null,
        ])->save();
    }

    private function giveUp(WorkflowRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => WorkflowRunStatus::Failed,
            'finished_at' => now(),
            'resume_at' => null,
            'resume_plan' => null,
            'failure_reason' => $reason,
        ])->save();
    }
}
