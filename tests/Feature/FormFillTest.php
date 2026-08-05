<?php

use App\Enums\FormFieldType;
use App\Enums\SystemRole;
use App\Features\Forms;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A form somebody in the workspace can walk up to and answer.
 *
 * The queue is faked throughout: what happens after a submission — the bot
 * message, any workflow — is tested where those live. Here the question is only
 * whether the answers land.
 *
 * @return array{0: User, 1: Workspace, 2: Form, 3: User}
 */
function fillableForm(array $state = []): array
{
    Queue::fake();

    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    FormField::factory()->for($form)->at(0)->create(['key' => 'reden', 'label' => 'Waarom?']);
    FormField::factory()->for($form)->at(1)->optional()->create([
        'key' => 'wanneer',
        'label' => 'Wanneer?',
        'type' => FormFieldType::Date,
    ]);

    $filler = User::factory()->create();
    joinWorkspace($workspace, $filler, SystemRole::Member);

    return [$author, $workspace, $form, $filler];
}

it('draws the questions for a member who may answer', function () {
    [, $workspace, $form, $filler] = fillableForm();

    actingAs($filler)
        ->get(route('chat.forms.show', [$workspace, $form]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('forms/fill')
            ->has('form.fields', 2)
            ->where('canSubmit', true)
            ->where('hasSubmitted', false)
            // Nobody is anonymous on this side of the door.
            ->where('anonymous', false)
            // The blank answer per question, so the browser never has to guess
            // that a date starts empty and a tickbox starts false.
            ->has('blank.reden'));
});

it('stores what somebody wrote, question and all', function () {
    [, $workspace, $form, $filler] = fillableForm();

    actingAs($filler)
        ->post(route('chat.forms.submit', [$workspace, $form]), [
            'answers' => ['reden' => 'Twee weken zon', 'wanneer' => '2026-09-01'],
        ])
        ->assertRedirect();

    $submission = FormSubmission::sole();

    expect($submission->submitted_by)->toBe($filler->id)
        ->and($submission->via_link)->toBeFalse()
        ->and($submission->answers)->toHaveCount(2)
        ->and($submission->keyedAnswers()['reden'])->toBe('Twee weken zon')
        // The wording is copied onto the answer, not read through the field.
        ->and($submission->answers->first()->question)->toBe('Waarom?');
});

it('refuses an answer that leaves a required question empty', function () {
    [, $workspace, $form, $filler] = fillableForm();

    actingAs($filler)
        ->post(route('chat.forms.submit', [$workspace, $form]), [
            'answers' => ['reden' => '', 'wanneer' => null],
        ])
        ->assertSessionHasErrors('answers.reden');

    expect(FormSubmission::count())->toBe(0);
});

it('takes a submission that leaves an optional question empty', function () {
    [, $workspace, $form, $filler] = fillableForm();

    actingAs($filler)
        ->post(route('chat.forms.submit', [$workspace, $form]), [
            'answers' => ['reden' => 'Zon', 'wanneer' => null],
        ])
        ->assertRedirect();

    expect(FormSubmission::sole()->keyedAnswers()['wanneer'])->toBe('—');
});

it('takes nothing once the form is shut', function (array $state) {
    [, $workspace, $form, $filler] = fillableForm($state);

    actingAs($filler)
        ->post(route('chat.forms.submit', [$workspace, $form]), [
            'answers' => ['reden' => 'Zon'],
        ])
        ->assertForbidden();

    expect(FormSubmission::count())->toBe(0);
})->with([
    'stopped by hand' => [['closed_at' => now()->subHour()]],
    'moment passed' => [['closes_at' => now()->subHour()]],
]);

it('takes only one submission per person unless the form says otherwise', function () {
    [, $workspace, $form, $filler] = fillableForm();

    $answers = ['answers' => ['reden' => 'Zon']];

    actingAs($filler)->post(route('chat.forms.submit', [$workspace, $form]), $answers)->assertRedirect();
    actingAs($filler)->post(route('chat.forms.submit', [$workspace, $form]), $answers)->assertForbidden();

    expect(FormSubmission::count())->toBe(1);

    $form->forceFill(['allows_multiple_submissions' => true])->save();

    actingAs($filler)->post(route('chat.forms.submit', [$workspace, $form]), $answers)->assertRedirect();

    expect(FormSubmission::count())->toBe(2);
});

it('says so on the page when this person already answered', function () {
    [, $workspace, $form, $filler] = fillableForm();

    FormSubmission::factory()->for($form)->create(['submitted_by' => $filler->id]);

    actingAs($filler)
        ->get(route('chat.forms.show', [$workspace, $form]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSubmit', false)->where('hasSubmitted', true));
});

it('keeps somebody from another workspace out entirely', function () {
    [, $workspace, $form] = fillableForm();

    $stranger = User::factory()->create();
    workspaceWithMember($stranger);

    actingAs($stranger)
        ->get(route('chat.forms.show', [$workspace, $form]))
        ->assertForbidden();
});

it('has no such address where the workspace switched forms off', function () {
    [, $workspace, $form, $filler] = fillableForm();

    Feature::for($workspace)->deactivate(Forms::class);

    actingAs($filler)
        ->get(route('chat.forms.show', [$workspace, $form]))
        ->assertNotFound();
});

it('lets a guest answer a form they were pointed at', function () {
    [, $workspace, $form] = fillableForm();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    actingAs($guest)
        ->post(route('chat.forms.submit', [$workspace, $form]), [
            'answers' => ['reden' => 'Ik lever de spullen'],
        ])
        ->assertRedirect();

    expect(FormSubmission::sole()->submitted_by)->toBe($guest->id);
});

it('puts a form in a channel as an ordinary message with a card on it', function () {
    [$author, $workspace, $form] = fillableForm();

    $channel = channelWithMember($workspace, $author);

    actingAs($author)
        ->post(route('chat.forms.post', [$workspace, $channel]), ['form_id' => $form->id])
        ->assertRedirect();

    $message = $channel->messages()->sole();

    expect($message->body)->toContain(route('chat.forms.show', [$workspace->slug, $form->id]));

    $card = present($message)['formCard'];

    expect($card['id'])->toBe($form->id)
        ->and($card['fieldCount'])->toBe(2)
        ->and($card['isFillable'])->toBeTrue()
        // Deliberately absent: anything about who answered, or how many did.
        ->and($card)->not->toHaveKeys(['submissions', 'hasSubmitted', 'submitters']);
});

it('refuses to put a form in a channel of another workspace', function () {
    [$author, $workspace, $form] = fillableForm();

    [$stranger, $elsewhere] = formAuthor();
    $channel = channelWithMember($elsewhere, $stranger);

    actingAs($author)
        ->post(route('chat.forms.post', [$workspace, $channel]), ['form_id' => $form->id])
        ->assertNotFound();
});
