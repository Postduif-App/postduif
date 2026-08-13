<?php

use App\Actions\Forms\SubmitForm;
use App\Enums\FormFieldType;
use App\Enums\SystemRole;
use App\Features\Forms;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\Triggers\FormSubmittedTrigger;
use Laravel\Pennant\Feature;

/**
 * A workspace with forms and workflows both switched on, a form with two
 * questions, and somebody to answer them.
 *
 * @return array{0: User, 1: User, 2: Form, 3: Workspace, 4: Channel}
 */
function formWorkflowScene(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(Forms::class);
    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'title' => 'Vakantieaanvraag',
    ]);

    FormField::factory()->for($form)->at(0)->create([
        'key' => 'reden',
        'label' => 'Waarom vraag je dit aan?',
    ]);
    FormField::factory()->for($form)->ofType(FormFieldType::Number)->at(1)->create([
        'key' => 'dagen',
        'label' => 'Hoeveel dagen?',
    ]);

    $filler = User::factory()->create();
    joinWorkspace($workspace, $filler);

    return [$owner, $filler, $form->refresh(), $workspace, channelWithMember($workspace, $owner)];
}

/** A switched-on workflow waiting for one form, with one harmless step. */
function formWorkflow(User $owner, Channel $target, ?string $formId): Workflow
{
    $workflow = Workflow::factory()
        ->enabled()
        ->triggeredBy(FormSubmittedTrigger::key(), $formId === null ? [] : ['form_id' => $formId])
        ->create([
            'workspace_id' => $target->workspace_id,
            'created_by' => $owner->id,
            'name' => 'Verlof melden',
        ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $target->id,
    ])->create();

    return $workflow;
}

function runsOf(Workflow $workflow): int
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->count();
}

it('is called form-submitted, which is what a workflow stores', function () {
    expect(FormSubmittedTrigger::key())->toBe('form-submitted');
});

it('starts the workflow that was waiting for this form', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, $form->id);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Twee weken zon.', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(1);
});

it('leaves the workflow that was waiting for another form alone', function () {
    [$owner, $filler, $form, $workspace, $channel] = formWorkflowScene();

    $other = Form::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    $workflow = formWorkflow($owner, $channel, $other->id);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(0);
});

/** A half-written workflow is skipped rather than read as "every form". */
it('runs nothing when no form was chosen at all', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, null);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(0);
});

it('leaves a switched-off workflow alone, however right the form is', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, $form->id);
    $workflow->forceFill(['enabled_at' => null])->save();

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(0);
});

it('stays out of a workflow in another workspace that names the same form', function () {
    [$owner, $filler, $form] = formWorkflowScene();

    $elsewhere = Workspace::factory()->create();
    $stranger = User::factory()->create();
    joinWorkspace($elsewhere, $stranger, SystemRole::Admin);
    Feature::for($elsewhere)->activate(WorkflowsFeature::class);

    $workflow = formWorkflow($stranger, channelWithMember($elsewhere, $stranger), $form->id);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(0);
});

it('hands the workflow the form, the person and every answer by its key', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, $form->id);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Twee weken zon.', 'dagen' => '10']);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect(data_get($run->context, 'trigger.form.id'))->toBe($form->id)
        ->and(data_get($run->context, 'trigger.form.title'))->toBe('Vakantieaanvraag')
        ->and(data_get($run->context, 'trigger.user.id'))->toBe($filler->id)
        ->and(data_get($run->context, 'trigger.user.name'))->toBe($filler->name)
        ->and(data_get($run->context, 'trigger.answers.reden'))->toBe('Twee weken zon.')
        // Read back the way a person reads it, not the way it was stored.
        ->and(data_get($run->context, 'trigger.answers.dagen'))->toBe('10');
});

it('passes on a question that was left empty as a dash rather than as nothing', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $form->fields()->where('key', 'dagen')->update(['required' => false]);
    $workflow = formWorkflow($owner, $channel, $form->id);

    app(SubmitForm::class)->handle($form->refresh(), $filler, ['reden' => 'Zon', 'dagen' => '']);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect(data_get($run->context, 'trigger.answers'))->toHaveKey('dagen')
        ->and(data_get($run->context, 'trigger.answers.dagen'))->toBe('—');
});

/**
 * The form promised the filler we would not know who they were, so the id is
 * empty — and the name says "anoniem" rather than reading as a bug.
 */
it('names an anonymous submission without inventing somebody', function () {
    [$owner, , $form, , $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, $form->id);

    app(SubmitForm::class)->handle($form, null, ['reden' => 'Zon', 'dagen' => '10'], viaLink: true);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect(data_get($run->context, 'trigger.user.id'))->toBeNull()
        ->and(data_get($run->context, 'trigger.user.name'))->toBe('anoniem');
});

it('starts every workflow that was waiting for the same form', function () {
    [$owner, $filler, $form, , $channel] = formWorkflowScene();
    $first = formWorkflow($owner, $channel, $form->id);
    $second = formWorkflow($owner, $channel, $form->id);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($first))->toBe(1)
        ->and(runsOf($second))->toBe(1);
});

/**
 * The feature check used to live only in the builder, which a listener never
 * passes through: a workspace that switched forms off kept setting off the
 * workflows that were written while it had them. The shared listener base asks
 * availableFor() before it starts anything, which is what closes that.
 */
it('does not start a form workflow in a workspace that has switched forms off', function () {
    [$owner, $filler, $form, $workspace, $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, $form->id);

    Feature::for($workspace)->deactivate(Forms::class);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon', 'dagen' => '10']);

    expect(runsOf($workflow))->toBe(0);
});

/** Nothing to point a form trigger at in a workspace that has no forms. */
it('is only offered where forms are switched on', function () {
    [$owner, , , $workspace, $channel] = formWorkflowScene();
    $workflow = formWorkflow($owner, $channel, null);

    expect(FormSubmittedTrigger::availableFor($workflow))->toBeTrue();

    Feature::for($workspace)->deactivate(Forms::class);

    expect(FormSubmittedTrigger::availableFor($workflow->fresh()))->toBeFalse();
});

it('promises the answers as one word, because the keys belong to the form', function () {
    expect(FormSubmittedTrigger::provides())
        ->toHaveKeys(['form.id', 'form.title', 'user.id', 'user.name', 'answers'])
        ->and(FormSubmittedTrigger::fields())->toHaveCount(1)
        ->and(FormSubmittedTrigger::fields()[0]->key)->toBe('form_id');
});
