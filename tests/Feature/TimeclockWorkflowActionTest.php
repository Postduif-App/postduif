<?php

use App\Actions\Timeclock\ClockIn;
use App\Actions\Timeclock\RecordShift;
use App\Actions\Workflows\RunWorkflow;
use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Features\Timeclock;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * A workspace that keeps time and runs workflows, with somebody in it.
 *
 * @return array{0: User, 1: Workspace, 2: Workflow}
 */
function timeclockActionScene(): array
{
    $member = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($member, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Timeclock::class);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    return [$member, $workspace, $workflow];
}

it('closes the shift that was left running', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    app(ClockIn::class)->handle($member, $workspace, Carbon::now()->subHours(9));

    $run = runStep($workflow, 'clock-out', ['user_id' => $member->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($member->openShiftIn($workspace))->toBeNull()
        ->and(data_get($run->context, 'steps.0.shift.was_running'))->toBeTrue()
        ->and(data_get($run->context, 'steps.0.shift.hours'))->toBeGreaterThan(8);
});

/**
 * The half of "een persoon uit een variabele" that can be had without touching
 * the picker: an empty box means whoever the trigger was about, which is what
 * makes this usable from the timeclock trigger at all — the member is different
 * every time and no list could name them.
 */
it('takes the person from the trigger when nobody is named', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    app(ClockIn::class)->handle($member, $workspace, Carbon::now()->subHours(2));

    $run = runStep($workflow, 'clock-out', [], [
        'trigger' => ['user' => ['id' => $member->id, 'name' => 'Sanne']],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($member->openShiftIn($workspace))->toBeNull()
        ->and(data_get($run->context, 'steps.0.user.name'))->toBe('Sanne');
});

/** Nobody named and nothing in the trigger is a step that cannot mean anything. */
it('says so when there is no person anywhere', function () {
    [, , $workflow] = timeclockActionScene();

    $run = runStep($workflow, 'clock-out', []);

    expect($run->status)->toBe(WorkflowRunStatus::Failed);
});

/**
 * A workflow that ran on a quiet Tuesday found nothing to close. That is the
 * state it wanted, not a failure.
 */
it('reports nothing running without failing', function () {
    [$member, , $workflow] = timeclockActionScene();

    $run = runStep($workflow, 'clock-out', ['user_id' => $member->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.shift.was_running'))->toBeFalse()
        ->and(data_get($run->context, 'steps.0.shift.hours'))->toBe(0);
});

it('adds up a week and leaves the numbers for the next step', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    $monday = $member->localNow()->startOfWeek();

    app(RecordShift::class)->handle(
        $member,
        $workspace,
        $monday->copy()->addHours(9),
        $monday->copy()->addHours(17),
    );
    app(RecordShift::class)->handle(
        $member,
        $workspace,
        $monday->copy()->addDay()->addHours(9),
        $monday->copy()->addDay()->addHours(12),
    );

    $run = runStep($workflow, 'summarise-hours', ['user_id' => $member->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        /*
         * toEqual rather than toBe: the number goes through a JSON column, and
         * PHP writes 11.0 as 11 unless it is asked not to. What matters is the
         * quantity, not which side of the round trip you look from.
         */
        ->and(data_get($run->context, 'steps.0.hours.total'))->toEqual(11)
        ->and(data_get($run->context, 'steps.0.hours.days_worked'))->toBe(2)
        ->and(data_get($run->context, 'steps.0.hours.spoken'))->toContain('11')
        ->and(data_get($run->context, 'steps.0.hours.from'))->toBe($monday->toDateString());
});

it('adds up last week when that is what was asked', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    $lastMonday = $member->localNow()->startOfWeek()->subWeek();

    app(RecordShift::class)->handle(
        $member,
        $workspace,
        $lastMonday->copy()->addHours(9),
        $lastMonday->copy()->addHours(14),
    );

    $thisWeek = runStep($workflow, 'summarise-hours', ['user_id' => $member->id, 'week' => 'this']);

    expect(data_get($thisWeek->context, 'steps.0.hours.total'))->toEqual(0);

    $lastWeek = runStep(
        Workflow::factory()->enabled()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $member->id,
        ]),
        'summarise-hours',
        ['user_id' => $member->id, 'week' => 'last'],
    );

    expect(data_get($lastWeek->context, 'steps.0.hours.total'))->toEqual(5)
        ->and(data_get($lastWeek->context, 'steps.0.hours.from'))->toBe($lastMonday->toDateString());
});

/**
 * The whole point of an action that sends nothing: the numbers go into whatever
 * the next step says.
 */
it('hands the summary to a following step', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    $channel = channelWithMember($workspace, $member);

    $monday = $member->localNow()->startOfWeek();

    app(RecordShift::class)->handle(
        $member,
        $workspace,
        $monday->copy()->addHours(9),
        $monday->copy()->addHours(13),
    );

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('summarise-hours', ['user_id' => $member->id])->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => '{{ steps.0.user.name }} draaide {{ steps.0.hours.spoken }}.',
        ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($channel->messages()->latest('id')->value('body'))
        ->toBe('Sanne draaide 4 uur en 0 minuten.');
});

it('stays out of a workspace that has switched the clock off', function () {
    [$member, $workspace, $workflow] = timeclockActionScene();

    Feature::for($workspace)->deactivate(Timeclock::class);

    $run = runStep($workflow, 'summarise-hours', ['user_id' => $member->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed);
});

it('cannot clock out somebody from another workspace', function () {
    [, , $workflow] = timeclockActionScene();

    $stranger = User::factory()->create();
    $elsewhere = Workspace::factory()->create();
    Feature::for($elsewhere)->activate(Timeclock::class);
    joinWorkspace($elsewhere, $stranger);

    app(ClockIn::class)->handle($stranger, $elsewhere, Carbon::now()->subHours(3));

    $run = runStep($workflow, 'clock-out', ['user_id' => $stranger->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($stranger->openShiftIn($elsewhere))->not->toBeNull();
});
