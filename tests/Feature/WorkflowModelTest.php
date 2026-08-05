<?php

use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Features\Workflows;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A workspace that has switched workflows on, and a beheerder in it.
 *
 * The feature is activated by hand, which is the point of it: workflows are off
 * until somebody says otherwise, so every test that expects them to work has to
 * say so out loud.
 *
 * @return array{0: User, 1: Workspace}
 */
function workspaceWithWorkflows(SystemRole $role = SystemRole::Admin): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(Workflows::class);

    return [$user, $workspace];
}

it('signs a workflow his messages with its own name until it is given one for them', function () {
    $workflow = Workflow::factory()->create(['name' => 'Storingsmelder', 'bot_name' => null]);

    expect($workflow->botName())->toBe('Storingsmelder');

    $workflow->update(['bot_name' => 'Storingsdienst']);

    expect($workflow->botName())->toBe('Storingsdienst');
});

it('leaves a new workflow switched off', function () {
    $workflow = Workflow::factory()->create();

    expect($workflow->isEnabled())->toBeFalse()
        ->and($workflow->enabled_at)->toBeNull();
});

it('only offers up the workflows of this workspace that are on and listening for this trigger', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    $wanted = Workflow::factory()->enabled()->triggeredBy('message-keyword')->create([
        'workspace_id' => $workspace->id,
    ]);

    Workflow::factory()->triggeredBy('message-keyword')->create([
        'workspace_id' => $workspace->id,
    ]);

    Workflow::factory()->enabled()->triggeredBy('channel-join')->create([
        'workspace_id' => $workspace->id,
    ]);

    Workflow::factory()->enabled()->triggeredBy('message-keyword')->create([
        'workspace_id' => $other->id,
    ]);

    $found = Workflow::query()->listeningFor($workspace, 'message-keyword')->get();

    expect($found->pluck('id')->all())->toBe([$wanted->id]);
});

it('reads a trigger setting through one seam, and says so when it is missing', function () {
    $workflow = Workflow::factory()->triggeredBy('message-keyword', [
        'keywords' => ['storing'],
    ])->create();

    expect($workflow->triggerSetting('keywords'))->toBe(['storing'])
        ->and($workflow->triggerSetting('kanaal', 'geen'))->toBe('geen');
});

it('keeps the steps in the order they were given', function () {
    $workflow = Workflow::factory()->create();

    WorkflowStep::factory()->at(2)->doing('pin-message')->for($workflow)->create();
    WorkflowStep::factory()->at(0)->doing('send-channel-message')->for($workflow)->create();
    WorkflowStep::factory()->at(1)->doing('delay')->for($workflow)->create();

    expect($workflow->steps->pluck('action_type')->all())
        ->toBe(['send-channel-message', 'delay', 'pin-message']);
});

it('does not treat an empty condition as a condition', function () {
    $never = WorkflowStep::factory()->create(['condition' => null]);
    $emptied = WorkflowStep::factory()->create(['condition' => []]);
    $real = WorkflowStep::factory()->onlyIf(['path' => 'trigger.user.role', 'is' => 'guest'])->create();

    expect($never->hasCondition())->toBeFalse()
        ->and($emptied->hasCondition())->toBeFalse()
        ->and($real->hasCondition())->toBeTrue();
});

it('writes what a step remembered straight to the run', function () {
    $run = WorkflowRun::factory()->create();

    $run->remember('steps.0.channel_id', 42);

    expect($run->fresh()->context)->toBe(['steps' => [['channel_id' => 42]]]);
});

it('only calls a waiting run due once its moment has passed', function () {
    $due = WorkflowRun::factory()->waiting()->create();
    WorkflowRun::factory()->waiting('+1 hour')->create();
    WorkflowRun::factory()->create();

    expect(WorkflowRun::query()->due()->pluck('id')->all())->toBe([$due->id]);
});

it('keeps a step run readable after the step it describes is gone', function () {
    $step = WorkflowStep::factory()->doing('archive-channel')->create();

    $stepRun = WorkflowStepRun::factory()->create([
        'workflow_step_id' => $step->id,
        'action_type' => 'archive-channel',
        'position' => 3,
    ]);

    $step->delete();

    $stepRun->refresh();

    expect($stepRun->exists)->toBeTrue()
        ->and($stepRun->workflow_step_id)->toBeNull()
        ->and($stepRun->action_type)->toBe('archive-channel')
        ->and($stepRun->position)->toBe(3);
});

it('tells a run that is still going from one that is over', function () {
    expect(WorkflowRunStatus::Running->isOpen())->toBeTrue()
        ->and(WorkflowRunStatus::Waiting->isOpen())->toBeTrue()
        ->and(WorkflowRunStatus::Succeeded->isOpen())->toBeFalse()
        ->and(WorkflowRunStatus::Failed->isOpen())->toBeFalse();
});

it('keeps a skipped step apart from one that ran', function () {
    $skipped = WorkflowStepRun::factory()->skipped()->create();

    expect($skipped->status)->toBe(WorkflowStepStatus::Skipped)
        ->and($skipped->status)->not->toBe(WorkflowStepStatus::Succeeded);
});

it('offers workflows to nobody until the workspace switches them on', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    expect($user->can('manageWorkflows', $workspace))->toBeFalse();
});

it('lets a beheerder write workflows and keeps an ordinary member out', function () {
    [$admin, $workspace] = workspaceWithWorkflows();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    expect($admin->can('manageWorkflows', $workspace))->toBeTrue()
        ->and($member->can('manageWorkflows', $workspace))->toBeFalse();
});

it('judges a workflow by the workspace it belongs to, not the one you are in', function () {
    [$admin, $workspace] = workspaceWithWorkflows();
    [, $elsewhere] = workspaceWithWorkflows();

    $mine = Workflow::factory()->create(['workspace_id' => $workspace->id]);
    $theirs = Workflow::factory()->create(['workspace_id' => $elsewhere->id]);

    expect($admin->can('update', $mine))->toBeTrue()
        ->and($admin->can('update', $theirs))->toBeFalse();
});

it('takes a workflow and everything under it when the workspace goes', function () {
    $workspace = Workspace::factory()->create();
    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id]);
    $step = WorkflowStep::factory()->for($workflow)->create();
    $run = WorkflowRun::factory()->for($workflow)->create();
    $stepRun = WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'workflow_step_id' => $step->id,
    ]);

    $workspace->delete();

    expect(Workflow::query()->whereKey($workflow->id)->exists())->toBeFalse()
        ->and(WorkflowStep::query()->whereKey($step->id)->exists())->toBeFalse()
        ->and(WorkflowRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(WorkflowStepRun::query()->whereKey($stepRun->id)->exists())->toBeFalse();
});

it('leaves a workflow ownerless rather than deleting it when its author goes', function () {
    $author = User::factory()->create();
    $workflow = Workflow::factory()->create(['created_by' => $author->id]);

    $author->delete();

    expect($workflow->fresh()?->created_by)->toBeNull();
});
