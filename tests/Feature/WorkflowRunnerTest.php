<?php

use App\Actions\Workflows\RunWorkflow;
use App\Actions\Workflows\StartWorkflow;
use App\Enums\WorkflowBranch;
use App\Enums\WorkflowConditionOutcome;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Features\Workflows as WorkflowsFeature;
use App\Jobs\RunWorkflowJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowDepth;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowPaused;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/** Writes down that it ran, and hands the next step something to read. */
class NotingAction extends WorkflowAction
{
    /** @var list<string> */
    public static array $ran = [];

    public static function label(): string
    {
        return 'Noteer';
    }

    public static function description(): string
    {
        return 'Alleen voor de test.';
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [WorkflowField::text('mark', 'Merkteken')];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        self::$ran[] = (string) $context->setting('mark', '?');

        return ['mark' => $context->setting('mark')];
    }
}

/** Says the run cannot sensibly go on. */
class StumblingAction extends WorkflowAction
{
    public static function label(): string
    {
        return 'Struikel';
    }

    public static function description(): string
    {
        return 'Alleen voor de test.';
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        throw new RuntimeException('Dit kanaal bestaat niet meer.');
    }
}

/** Puts the run down for an hour, the way the delay action does. */
class WaitingAction extends WorkflowAction
{
    public static function label(): string
    {
        return 'Wacht';
    }

    public static function description(): string
    {
        return 'Alleen voor de test.';
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        throw new WorkflowPaused(now()->addHour());
    }
}

/** Starts another workflow from inside one, the way a posted message would. */
class ChainingAction extends WorkflowAction
{
    public static ?Workflow $next = null;

    /** @var list<int> */
    public static array $depths = [];

    public static function label(): string
    {
        return 'Ketting';
    }

    public static function description(): string
    {
        return 'Alleen voor de test.';
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        self::$depths[] = WorkflowDepth::current();

        if (self::$next !== null) {
            app(StartWorkflow::class)->handle(self::$next);
        }

        return null;
    }
}

beforeEach(function () {
    NotingAction::$ran = [];
    ChainingAction::$depths = [];
    ChainingAction::$next = null;
    WorkflowDepth::reset();

    /*
     * The register is swapped rather than added to. These tests are about what
     * the runner does with a step, not about any particular action, and a real
     * one would tie every assertion here to whatever that action happens to do.
     */
    app()->instance(WorkflowRegistry::class, new WorkflowRegistry(actions: [
        NotingAction::class,
        StumblingAction::class,
        ChainingAction::class,
        WaitingAction::class,
    ]));
});

/**
 * A workflow that is switched on, in a workspace that allows them, with an
 * owner still present — the three things StartWorkflow insists on.
 *
 * @return array{0: Workflow, 1: Workspace}
 */
function readyWorkflow(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);

    return [$workflow, $workspace];
}

it('walks the steps in order and writes a line for each', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'eerst'])->create();
    WorkflowStep::factory()->for($workflow)->at(1)->doing('noting-action', ['mark' => 'daarna'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create();

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['eerst', 'daarna'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($run->stepRuns->pluck('status')->all())
        ->toBe([WorkflowStepStatus::Succeeded, WorkflowStepStatus::Succeeded]);
});

it('keeps what a step handed back, under that step his own place in the row', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'hallo'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create();

    app(RunWorkflow::class)->handle($run);

    expect($run->fresh()->context)->toHaveKey('steps.0.mark')
        ->and(data_get($run->fresh()->context, 'steps.0.mark'))->toBe('hallo');
});

it('stops at a step that fails rather than running the ones written after it', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'eerst'])->create();
    WorkflowStep::factory()->for($workflow)->at(1)->doing('stumbling-action')->create();
    WorkflowStep::factory()->for($workflow)->at(2)->doing('noting-action', ['mark' => 'nooit'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create();

    app(RunWorkflow::class)->handle($run);

    $run->refresh();

    expect(NotingAction::$ran)->toBe(['eerst'])
        ->and($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toBe('Dit kanaal bestaat niet meer.')
        ->and($run->stepRuns)->toHaveCount(2)
        ->and($run->stepRuns->last()->status)->toBe(WorkflowStepStatus::Failed)
        ->and($run->stepRuns->last()->failure_reason)
        ->toBe('Dit kanaal bestaat niet meer.');
});

it('says so plainly when a step points at an action that is gone', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('een-verdwenen-actie')->create();

    $run = WorkflowRun::factory()->for($workflow)->create();

    app(RunWorkflow::class)->handle($run);

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->fresh()->failure_reason)->toContain('een-verdwenen-actie');
});

it('picks up where a waiting run left off', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'al gedaan'])->create();
    WorkflowStep::factory()->for($workflow)->at(1)->doing('noting-action', ['mark' => 'nu pas'])->create();

    $run = WorkflowRun::factory()->for($workflow)->waiting()->create(['resume_position' => 1]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['nu pas'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

it('refuses to carry on with a workflow that was switched off while it waited', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'nooit'])->create();

    $run = WorkflowRun::factory()->for($workflow)->waiting()->create();

    $workflow->disable();

    app(RunWorkflow::class)->handle($run->fresh());

    expect(NotingAction::$ran)->toBe([])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Failed);
});

it('refuses to carry on once the author of the workflow is gone', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'nooit'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create();

    $workflow->owner->delete();

    app(RunWorkflow::class)->handle($run->fresh());

    expect(NotingAction::$ran)->toBe([])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Failed);
});

it('does not walk a run that is already over', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('noting-action', ['mark' => 'nooit'])->create();

    $run = WorkflowRun::factory()->for($workflow)->succeeded()->create();

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe([]);
});

it('writes the run down and leaves the work to the queue', function () {
    Queue::fake();

    [$workflow] = readyWorkflow();

    $run = app(StartWorkflow::class)->handle($workflow, ['user' => ['name' => 'Pietje']]);

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(WorkflowRunStatus::Running)
        ->and(data_get($run->context, 'trigger.user.name'))->toBe('Pietje');

    Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->runId === $run->id);
});

it('starts nothing for a workflow that is off, ownerless, or in a workspace that said no', function () {
    Queue::fake();

    [$off] = readyWorkflow();
    $off->disable();

    [$orphan] = readyWorkflow();
    $orphan->update(['created_by' => null]);

    [$unwanted, $workspace] = readyWorkflow();
    Feature::for($workspace)->deactivate(WorkflowsFeature::class);

    $start = app(StartWorkflow::class);

    expect($start->handle($off->fresh()))->toBeNull()
        ->and($start->handle($orphan->fresh()))->toBeNull()
        ->and($start->handle($unwanted->fresh()))->toBeNull();

    Queue::assertNothingPushed();
});

it('lets one workflow set off another, but not without end', function () {
    [$first] = readyWorkflow();
    [$second] = readyWorkflow();

    WorkflowStep::factory()->for($first)->at(0)->doing('chaining-action')->create();
    WorkflowStep::factory()->for($second)->at(0)->doing('chaining-action')->create();

    // Each one sets the other off, which is the loop this guard exists for.
    ChainingAction::$next = $second;

    $run = WorkflowRun::factory()->for($first)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    /*
     * Three deep and then no further. The queue runs inline here, so this is
     * the whole chain: without the guard these two would keep starting each
     * other for as long as the process lasted.
     */
    expect(ChainingAction::$depths)->toBe([1, 2, 3])
        ->and(WorkflowRun::query()->count())->toBe(3);
});

it('starts nothing once the chain is as long as it may get', function () {
    [$workflow] = readyWorkflow();

    $started = WorkflowDepth::within(WorkflowDepth::MAX, fn () => app(StartWorkflow::class)->handle($workflow));

    expect($started)->toBeNull();
});

it('skips a step whose condition says no, and says that it skipped it', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('noting-action', ['mark' => 'wel'])
        ->onlyIf(['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Pietje'])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('noting-action', ['mark' => 'niet'])
        ->onlyIf(['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Iemand anders'])
        ->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['wel'])
        // The skipped one still gets a line: "nothing happened" has to be
        // answerable afterwards, and a missing row cannot answer it.
        ->and($run->stepRuns->pluck('status')->all())
        ->toBe([WorkflowStepStatus::Succeeded, WorkflowStepStatus::Skipped])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

/**
 * The same thing the condition test proves in isolation, once through the
 * runner: a number that arrives from a JSON column, compared as a quantity,
 * deciding whether a step gets its turn.
 */
it('lets a step through on how many rather than on what it says', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('noting-action', ['mark' => 'bijna om'])
        ->onlyIf(['path' => 'trigger.contract.days_until_expiry', 'operator' => 'less-or-equal', 'value' => '3'])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('noting-action', ['mark' => 'nog even'])
        ->onlyIf(['path' => 'trigger.contract.days_until_expiry', 'operator' => 'greater-than', 'value' => '3'])
        ->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['contract' => ['days_until_expiry' => 2]], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['bijna om'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

it('fills in what a step says with what the trigger saw', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('noting-action', ['mark' => 'hoi {{ trigger.user.name }}'])
        ->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['hoi Pietje']);
});

it('stops the whole run when a condition that guards the rest says no', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('noting-action', ['mark' => 'niet'])
        ->onlyIf([
            'match' => 'all',
            'otherwise' => WorkflowConditionOutcome::Stop->value,
            'rules' => [['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Iemand anders']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('noting-action', ['mark' => 'ook niet'])
        ->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe([])
        // One line, for the step that was asked. The steps below it were never
        // at the door, and a row saying they were skipped would suggest each
        // was considered on its own.
        ->and($run->stepRuns->pluck('status')->all())->toBe([WorkflowStepStatus::Skipped])
        // Not Failed: nothing went wrong, and a red run over a filter doing its
        // job is how somebody comes to ignore the red ones.
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Stopped)
        ->and($run->fresh()->failure_reason)->toBeNull();
});

it('carries on past a condition that only guards its own step', function () {
    [$workflow] = readyWorkflow();

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('noting-action', ['mark' => 'niet'])
        ->onlyIf([
            'match' => 'all',
            'otherwise' => WorkflowConditionOutcome::Skip->value,
            'rules' => [['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Iemand anders']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('noting-action', ['mark' => 'wel'])
        ->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['wel'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

it('runs the lane a fork chose and leaves the other one alone', function () {
    [$workflow] = readyWorkflow();

    $fork = WorkflowStep::factory()->for($workflow)->at(0)->forking()
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Pietje']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)->inLane($fork, WorkflowBranch::Then)
        ->doing('noting-action', ['mark' => 'wel'])->create();

    WorkflowStep::factory()->for($workflow)->at(2)->inLane($fork, WorkflowBranch::Else)
        ->doing('noting-action', ['mark' => 'niet'])->create();

    // What follows the fork runs whichever lane was taken: the two come back
    // together, which is what keeps a fork from being the end of a workflow.
    WorkflowStep::factory()->for($workflow)->at(3)
        ->doing('noting-action', ['mark' => 'daarna'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['wel', 'daarna'])
        // The fork writes a line saying which way it went. The lane it did not
        // take leaves no trace: those steps were never at the door.
        ->and($run->stepRuns()->where('action_type', 'branch')->first()->result)->toBe(['lane' => 'then'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

it('takes the other lane when the fork says no', function () {
    [$workflow] = readyWorkflow();

    $fork = WorkflowStep::factory()->for($workflow)->at(0)->forking()
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Iemand anders']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)->inLane($fork, WorkflowBranch::Then)
        ->doing('noting-action', ['mark' => 'wel'])->create();

    WorkflowStep::factory()->for($workflow)->at(2)->inLane($fork, WorkflowBranch::Else)
        ->doing('noting-action', ['mark' => 'anders'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect(NotingAction::$ran)->toBe(['anders']);
});

it('comes back to the lane it was in after a wait inside a fork', function () {
    [$workflow] = readyWorkflow();

    $fork = WorkflowStep::factory()->for($workflow)->at(0)->forking()
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [['path' => 'trigger.user.name', 'operator' => 'equals', 'value' => 'Pietje']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)->inLane($fork, WorkflowBranch::Then)
        ->doing('waiting-action')->create();

    WorkflowStep::factory()->for($workflow)->at(2)->inLane($fork, WorkflowBranch::Then)
        ->doing('noting-action', ['mark' => 'na het wachten'])->create();

    WorkflowStep::factory()->for($workflow)->at(3)->inLane($fork, WorkflowBranch::Else)
        ->doing('noting-action', ['mark' => 'nooit'])->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Waiting)
        ->and(NotingAction::$ran)->toBe([]);

    /*
     * The heart of it: the run wrote down what it still had to do rather than
     * where in a list it stood. Somebody changing the workflow's memory in the
     * meantime — which the trigger's name here stands in for — must not be able
     * to move a run that is already in one lane into the other.
     */
    $run->fresh()->forceFill(['context' => ['trigger' => ['user' => ['name' => 'Iemand anders']], 'depth' => 1]])->save();

    app(RunWorkflow::class)->handle($run->fresh());

    expect(NotingAction::$ran)->toBe(['na het wachten'])
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});
