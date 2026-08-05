<?php

use App\Enums\FormFieldType;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A form with answers already in it.
 *
 * @return array{0: User, 1: Workspace, 2: Form, 3: User}
 */
function answeredForm(): array
{
    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Vakantieaanvraag',
    ]);

    $reden = FormField::factory()->for($form)->at(0)->create(['key' => 'reden', 'label' => 'Waarom?']);

    $filler = User::factory()->create();
    joinWorkspace($workspace, $filler, SystemRole::Member);

    $submission = FormSubmission::factory()->for($form)->create(['submitted_by' => $filler->id]);

    FormAnswer::factory()->create([
        'form_submission_id' => $submission->id,
        'form_field_id' => $reden->id,
        'field_key' => 'reden',
        'question' => 'Waarom?',
        'type' => FormFieldType::ShortText,
        'value' => 'Twee weken zon',
    ]);

    return [$author, $workspace, $form, $filler];
}

it('shows the author what came back, with a column per question', function () {
    [$author, $workspace, $form, $filler] = answeredForm();

    actingAs($author)
        ->get(route('chat.forms.answers', [$workspace, $form]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/form-answers')
            ->has('submissions', 1)
            ->where('submissions.0.who', $filler->name)
            ->where('submissions.0.answers.reden', 'Twee weken zon')
            ->where('columns.0.key', 'reden')
            ->where('columns.0.label', 'Waarom?'));
});

it('says nothing about who sent in an anonymous one', function () {
    [$author, $workspace, $form] = answeredForm();

    // Newest first on that screen, and both of these would otherwise land in
    // the same second — so this one is given a moment of its own.
    $submission = FormSubmission::factory()->for($form)->anonymous()->create([
        'created_at' => now()->addMinute(),
    ]);

    FormAnswer::factory()->create([
        'form_submission_id' => $submission->id,
        'field_key' => 'reden',
        'question' => 'Waarom?',
        'value' => 'Van buiten',
    ]);

    actingAs($author)
        ->get(route('chat.forms.answers', [$workspace, $form]))
        ->assertOk()
        // Null rather than a word: the screen decides what to call it, and the
        // controller does not put words in its mouth.
        ->assertInertia(fn ($page) => $page
            ->where('submissions.0.who', null)
            ->where('submissions.0.viaLink', true));
});

it('keeps a column for a question that was deleted after people answered it', function () {
    [$author, $workspace, $form] = answeredForm();

    $form->fields()->delete();

    actingAs($author)
        ->get(route('chat.forms.answers', [$workspace, $form]))
        ->assertOk()
        // The wording lives on the answer, which is the whole reason it is
        // copied there — see the forms migration.
        ->assertInertia(fn ($page) => $page
            ->has('columns', 1)
            ->where('columns.0.label', 'Waarom?')
            ->where('submissions.0.answers.reden', 'Twee weken zon'));
});

it('hands the same thing over as a file', function () {
    [$author, $workspace, $form, $filler] = answeredForm();

    $response = actingAs($author)
        ->get(route('chat.forms.answers.export', [$workspace, $form]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    // Other people's answers have no business in a proxy's cache. Asserted on
    // the directive rather than the whole header: Symfony rewrites the order
    // and adds "private" of its own accord.
    expect($response->headers->get('cache-control'))->toContain('no-store');

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Waarom?')
        ->and($csv)->toContain('Twee weken zon')
        ->and($csv)->toContain($filler->name);
});

it('names the nameless in the file rather than leaving a blank cell', function () {
    [$author, $workspace, $form] = answeredForm();

    FormSubmission::factory()->for($form)->anonymous()->create();

    $csv = actingAs($author)
        ->get(route('chat.forms.answers.export', [$workspace, $form]))
        ->streamedContent();

    expect($csv)->toContain(__('forms.answers.anonymous'));
});

it('keeps a colleague away from somebody else\'s answers', function () {
    [, $workspace, $form] = answeredForm();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    setAbility($workspace, WorkspaceAbility::CreateForms, true, SystemRole::Member);

    actingAs($colleague)
        ->get(route('chat.forms.answers', [$workspace, $form]))
        ->assertForbidden();

    actingAs($colleague)
        ->get(route('chat.forms.answers.export', [$workspace, $form]))
        ->assertForbidden();
});

it('answers 404 for a form from another workspace', function () {
    [$author, $workspace] = answeredForm();
    [$stranger, $elsewhere] = formAuthor();

    $elsewheres = Form::factory()->create([
        'workspace_id' => $elsewhere->id,
        'created_by' => $stranger->id,
    ]);

    actingAs($author)
        ->get(route('chat.forms.answers', [$workspace, $elsewheres]))
        ->assertNotFound();
});
