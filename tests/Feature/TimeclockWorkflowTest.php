<?php

use App\Actions\Timeclock\ClockIn;
use App\Actions\Timeclock\ClockOut;
use App\Enums\SystemRole;
use App\Features\Timeclock as TimeclockFeature;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Workflows\Triggers\TimeclockTrigger;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * A workspace with both switches thrown: the clock somebody punches, and the
 * workflows that are waiting for it. Two features rather than one, because
 * either of them being off is a real state and the trigger has to survive it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function clockingScene(): array
{
    $owner = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(TimeclockFeature::class);

    return [$owner, $workspace, channelWithMember($workspace, $owner)];
}

it('starts a workflow when somebody clocks in', function () {
    [$owner, $workspace, $channel] = clockingScene();

    $workflow = listeningWorkflow($owner, 'timeclock', ['direction' => 'both'], $channel);

    Carbon::setTestNow('2026-08-05 06:30:00');

    app(ClockIn::class)->handle($owner, $workspace);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.punch.direction'))->toBe('in')
        // Half past eight on the clock this member reads, not half past six.
        ->and(data_get($run->context, 'trigger.punch.at'))->toBe('08:30')
        ->and(data_get($run->context, 'trigger.user.name'))->toBe($owner->name)
        ->and(data_get($run->context, 'trigger.shift.hours'))->toEqual(0);
});

it('hands a closing workflow the length of the shift', function () {
    [$owner, $workspace, $channel] = clockingScene();

    $workflow = listeningWorkflow($owner, 'timeclock', ['direction' => 'out'], $channel);

    Carbon::setTestNow('2026-08-05 06:00:00');
    app(ClockIn::class)->handle($owner, $workspace);

    Carbon::setTestNow('2026-08-05 13:30:00');
    app(ClockOut::class)->handle($owner, $workspace);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.punch.direction'))->toBe('out')
        ->and(data_get($run->context, 'trigger.shift.hours'))->toBe(7.5)
        ->and(data_get($run->context, 'trigger.shift.started_at'))->toBe('08:00');
});

it('leaves a workflow that only wants one direction alone on the other', function () {
    [$owner, $workspace, $channel] = clockingScene();

    $workflow = listeningWorkflow($owner, 'timeclock', ['direction' => 'out'], $channel);

    app(ClockIn::class)->handle($owner, $workspace);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);

    app(ClockOut::class)->handle($owner, $workspace);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('runs twice a shift for a workflow that wants both', function () {
    [$owner, $workspace, $channel] = clockingScene();

    $workflow = listeningWorkflow($owner, 'timeclock', ['direction' => 'both'], $channel);

    app(ClockIn::class)->handle($owner, $workspace);
    app(ClockOut::class)->handle($owner, $workspace);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(2);
});

it('does not fire again when somebody presses a button twice', function () {
    [$owner, $workspace, $channel] = clockingScene();

    $workflow = listeningWorkflow($owner, 'timeclock', ['direction' => 'both'], $channel);

    app(ClockIn::class)->handle($owner, $workspace);
    app(ClockIn::class)->handle($owner, $workspace);

    app(ClockOut::class)->handle($owner, $workspace);
    app(ClockOut::class)->handle($owner, $workspace);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(2);
});

it('does not offer the trigger in a workspace without a clock', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);

    expect(TimeclockTrigger::availableFor($workflow))->toBeFalse();

    Feature::for($workspace)->activate(TimeclockFeature::class);

    expect(TimeclockTrigger::availableFor($workflow->refresh()))->toBeTrue();
});
