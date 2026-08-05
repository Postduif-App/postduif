<?php

use App\Enums\FormFieldType;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Forms;
use App\Models\Channel;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A workspace that keeps forms, and somebody in it who may write one.
 *
 * The feature is switched on by hand, which is the point of it: forms are off
 * until a workspace says otherwise, so every test that wants one says so too.
 *
 * @return array{0: User, 1: Workspace}
 */
function formAuthor(SystemRole $role = SystemRole::Admin): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(Forms::class);

    return [$user, $workspace];
}

/** A form with one question, which is the least it takes to be fillable. */
function askableForm(Workspace $workspace, User $author, array $state = []): Form
{
    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    FormField::factory()->for($form)->create(['key' => 'reden', 'label' => 'Waarom?']);

    return $form;
}

/** @return array<string, mixed> */
function fieldPayload(array $overrides = []): array
{
    return [
        'id' => null,
        'type' => FormFieldType::ShortText->value,
        'label' => 'Waarom vraag je dit aan?',
        'hint' => null,
        'required' => true,
        'options' => [],
        ...$overrides,
    ];
}

it('lists nothing and offers a box where a workspace has no forms yet', function () {
    [$author, $workspace] = formAuthor();

    actingAs($author)
        ->get(route('chat.forms.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/forms')->has('forms', 0));
});

it('has no such screen where the workspace switched forms off', function () {
    [$author, $workspace] = formAuthor();

    Feature::for($workspace)->deactivate(Forms::class);

    actingAs($author)
        ->get(route('chat.forms.index', $workspace))
        ->assertNotFound();
});

it('sends a new form straight into the builder', function () {
    [$author, $workspace] = formAuthor();

    actingAs($author)
        ->post(route('chat.forms.store', $workspace), ['title' => 'Vakantieaanvraag'])
        ->assertRedirect();

    $form = Form::sole();

    expect($form->title)->toBe('Vakantieaanvraag')
        ->and($form->created_by)->toBe($author->id)
        ->and($form->workspace_id)->toBe($workspace->id);
});

it('refuses to make one for a role that may not', function () {
    [$author, $workspace] = formAuthor(SystemRole::Member);

    setAbility($workspace, WorkspaceAbility::CreateForms, false);

    actingAs($author)
        ->post(route('chat.forms.store', $workspace), ['title' => 'Vakantieaanvraag'])
        ->assertForbidden();

    expect(Form::count())->toBe(0);
});

it('saves the questions in the order they were sent', function () {
    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
    ]);

    actingAs($author)
        ->put(route('chat.forms.update', [$workspace, $form]), [
            'title' => 'Vakantieaanvraag',
            'description' => 'Vul dit in.',
            'fields' => [
                fieldPayload(['label' => 'Waarom?']),
                fieldPayload(['label' => 'Wanneer?', 'type' => FormFieldType::Date->value]),
            ],
        ])
        ->assertRedirect();

    $fields = $form->fresh()->fields;

    expect($fields->pluck('label')->all())->toBe(['Waarom?', 'Wanneer?'])
        ->and($fields->pluck('position')->all())->toBe([0, 1])
        // Derived once from the label, which is what a workflow refers to.
        ->and($fields->pluck('key')->all())->toBe(['waarom', 'wanneer']);
});

it('keeps a question its key when the wording is rewritten', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author);
    $field = $form->fields()->sole();

    actingAs($author)
        ->put(route('chat.forms.update', [$workspace, $form]), [
            'title' => $form->title,
            'fields' => [fieldPayload(['id' => $field->id, 'label' => 'Wat is de aanleiding?'])],
        ])
        ->assertRedirect();

    expect($field->fresh()->label)->toBe('Wat is de aanleiding?')
        // The rename must not break {{ trigger.answers.reden }}.
        ->and($field->fresh()->key)->toBe('reden');
});

it('refuses a choice question with fewer than two choices', function (array $options) {
    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $author->id]);

    actingAs($author)
        ->put(route('chat.forms.update', [$workspace, $form]), [
            'title' => 'Vakantieaanvraag',
            'fields' => [fieldPayload([
                'type' => FormFieldType::Choice->value,
                'options' => $options,
            ])],
        ])
        ->assertStatus(422);

    expect(FormField::count())->toBe(0);
})->with([
    'none at all' => [[]],
    'one is not a choice' => [['Ja']],
]);

it('refuses choices on a question that does not take any', function () {
    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $author->id]);

    actingAs($author)
        ->put(route('chat.forms.update', [$workspace, $form]), [
            'title' => 'Vakantieaanvraag',
            'fields' => [fieldPayload(['options' => ['Ja', 'Nee']])],
        ])
        ->assertStatus(422);
});

it('takes a channel for anonymous answers only from this workspace', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author);
    $elsewhere = Channel::factory()->create();

    actingAs($author)
        ->put(route('chat.forms.update', [$workspace, $form]), [
            'title' => $form->title,
            'notify_channel_id' => $elsewhere->id,
            'fields' => [],
        ])
        ->assertSessionHasErrors('notify_channel_id');
});

it('lets the author stop it and start it again', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author);

    actingAs($author)
        ->post(route('chat.forms.close', [$workspace, $form]))
        ->assertRedirect();

    expect($form->fresh()->isClosed())->toBeTrue();

    actingAs($author)
        ->delete(route('chat.forms.close', [$workspace, $form]))
        ->assertRedirect();

    expect($form->fresh()->isClosed())->toBeFalse();
});

it('drops a deadline that has already passed when it is reopened', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author, ['closes_at' => now()->subHour()]);

    actingAs($author)
        ->delete(route('chat.forms.close', [$workspace, $form]))
        ->assertRedirect();

    expect($form->fresh()->closes_at)->toBeNull()
        ->and($form->fresh()->isClosed())->toBeFalse();
});

it('hands out a link and kills the previous one', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author);

    actingAs($author)->post(route('chat.forms.share', [$workspace, $form]))->assertRedirect();

    $first = $form->fresh()->share_token;

    actingAs($author)->post(route('chat.forms.share', [$workspace, $form]))->assertRedirect();

    $second = $form->fresh()->share_token;

    expect($first)->not->toBeNull()
        ->and($second)->not->toBe($first);

    // The old address is gone rather than merely unadvertised.
    $this->get(route('forms.public.show', $first))->assertNotFound();
});

it('refuses to share to somebody who may write forms but not hand them out', function () {
    [$author, $workspace] = formAuthor(SystemRole::Member);

    setAbility($workspace, WorkspaceAbility::CreateForms, true);
    setAbility($workspace, WorkspaceAbility::ShareFormsPublicly, false);

    $form = askableForm($workspace, $author);

    actingAs($author)
        ->post(route('chat.forms.share', [$workspace, $form]))
        ->assertForbidden();

    expect($form->fresh()->share_token)->toBeNull();
});

it('withdraws a link that was handed out', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author, ['share_token' => 'wegwezen']);

    actingAs($author)->delete(route('chat.forms.share', [$workspace, $form]))->assertRedirect();

    expect($form->fresh()->share_token)->toBeNull();
});

it('keeps a colleague out of somebody else\'s form', function () {
    [$author, $workspace] = formAuthor();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    setAbility($workspace, WorkspaceAbility::CreateForms, true, SystemRole::Member);

    $form = askableForm($workspace, $author);

    actingAs($colleague)
        ->get(route('chat.forms.edit', [$workspace, $form]))
        ->assertForbidden();
});

it('lets whoever runs the workspace open anybody\'s form', function () {
    [$author, $workspace] = formAuthor();

    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    $form = askableForm($workspace, $author);

    actingAs($admin)
        ->get(route('chat.forms.edit', [$workspace, $form]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/form-edit'));
});

it('answers 404 for a form from another workspace', function () {
    [$author, $workspace] = formAuthor();
    [$stranger, $elsewhere] = formAuthor();

    $form = askableForm($elsewhere, $stranger);

    actingAs($author)
        ->get(route('chat.forms.edit', [$workspace, $form]))
        ->assertNotFound();
});

it('takes the form and everything filled into it away together', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author);
    FormSubmission::factory()->for($form)->create();

    actingAs($author)
        ->delete(route('chat.forms.destroy', [$workspace, $form]))
        ->assertRedirect();

    expect(Form::count())->toBe(0)
        ->and(FormSubmission::count())->toBe(0)
        ->and(FormField::count())->toBe(0);
});

it('never sends the link to somebody who may not hand the form out', function () {
    [$author, $workspace] = formAuthor();

    $form = askableForm($workspace, $author, ['share_token' => 'geheim-token']);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Admin);
    setAbility($workspace, WorkspaceAbility::ShareFormsPublicly, false, SystemRole::Admin);

    actingAs($colleague)
        ->get(route('chat.forms.edit', [$workspace, $form]))
        ->assertOk()
        // The token *is* the permission, so a page that renders it has given it
        // away — see FormController::edit.
        ->assertInertia(fn ($page) => $page->where('form.shareUrl', null)->where('canShare', false));
});
