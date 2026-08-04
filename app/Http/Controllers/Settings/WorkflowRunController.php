<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\WorkflowBranch;
use App\Enums\WorkflowStepKind;
use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Workflows\WorkflowRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a workflow has actually been doing.
 *
 * Not a luxury. A workflow does its work while nobody is watching, so without
 * this screen "er gebeurt niets" is a complaint that cannot be investigated —
 * there is no request to look at, no error on anybody's screen, and the only
 * record is the rows this reads.
 */
class WorkflowRunController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * A page of runs. Enough to see a pattern, few enough to read.
     */
    private const PER_PAGE = 25;

    public function index(Request $request, Workflow $workflow, WorkflowRegistry $registry): Response
    {
        abort_unless(
            $workflow->workspace_id === $this->currentWorkspace($request, 'manageWorkflows')->id,
            404,
        );

        $runs = $workflow->runs()
            ->with('stepRuns')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (WorkflowRun $run): array => [
                'id' => $run->id,
                'status' => $run->status->value,
                'statusLabel' => $run->status->label(),
                'startedAt' => $run->created_at?->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
                'resumeAt' => $run->resume_at?->toIso8601String(),
                'failureReason' => $run->failure_reason,

                /*
                 * The context as it stood, which is what the variables were at
                 * the time — and therefore the answer to most questions about
                 * why something odd ended up in a message.
                 *
                 * It holds message text and people's names, which is why this
                 * screen is a beheerder's and why runs get cleared out. The
                 * depth is stripped: it is the runner's own bookkeeping and
                 * means nothing to a reader.
                 */
                'context' => collect($run->context)->except('depth')->all(),

                'steps' => $run->stepRuns->map(fn (WorkflowStepRun $step): array => [
                    'position' => $step->position,

                    'actionType' => $step->action_type,
                    'action' => $this->actionLabel($registry, $step->action_type),

                    /*
                     * The two things that let the run be drawn as the shape it
                     * walked rather than as a list: which lane this step stood
                     * in, and — for a fork — which lane it sent the run down.
                     * Both are read from the run's own rows, so a workflow
                     * edited afterwards does not rewrite the picture.
                     */
                    'branch' => $step->branch?->value,
                    'branchLabel' => $step->branch?->label(),
                    'lane' => WorkflowBranch::tryFrom((string) data_get($step->result, 'lane'))?->label(),

                    'status' => $step->status->value,
                    'statusLabel' => $step->status->label(),
                    'failureReason' => $step->failure_reason,
                    'result' => $step->result,
                ])->all(),
            ]);

        return Inertia::render('settings/workflow-runs', [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'enabled' => $workflow->isEnabled(),
            ],
            'runs' => $runs,
        ]);
    }

    /**
     * An action as a person reads it, or its stored key when the register no
     * longer knows it.
     *
     * The fallback matters: a run that mentions an action since taken out
     * should still say which one, even if it can no longer say it nicely.
     */
    private function actionLabel(WorkflowRegistry $registry, string $key): string
    {
        /*
         * A fork is not in the register — it does nothing, so there is nothing
         * to register — but it does write a line, and that line has to say what
         * it is rather than repeat the word in the column.
         */
        if ($key === WorkflowStepKind::Branch->value) {
            return WorkflowStepKind::Branch->label();
        }

        $action = $registry->action($key);

        return $action === null ? $key : $action::label();
    }
}
