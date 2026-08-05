<?php

use App\Actions\Forms\SaveFormFields;
use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

/**
 * A form with one question on it, which is the smallest thing that can be
 * filled in at all.
 *
 * @return array{0: Form, 1: FormField}
 */
function formWithQuestion(array $state = [], array $field = []): array
{
    $form = Form::factory()->create($state);

    $question = FormField::factory()->for($form)->create([
        'key' => 'reden',
        'label' => 'Waarom vraag je dit aan?',
        ...$field,
    ]);

    return [$form->refresh(), $question];
}

it('is open until something closes it', function () {
    [$form] = formWithQuestion();

    expect($form->isClosed())->toBeFalse()
        ->and($form->acceptsAnswers())->toBeTrue();
});

it('is closed when somebody stopped it', function () {
    [$form] = formWithQuestion(['closed_at' => now()->subHour()]);

    expect($form->isClosed())->toBeTrue();
});

it('is closed when its moment has passed', function () {
    [$form] = formWithQuestion(['closes_at' => now()->subHour()]);

    expect($form->isClosed())->toBeTrue()
        // Nobody stopped it; it simply ran out.
        ->and($form->closed_at)->toBeNull();
});

it('is still open before its moment arrives', function () {
    [$form] = formWithQuestion(['closes_at' => now()->addHour()]);

    expect($form->isClosed())->toBeFalse();
});

/** Three ways to be unfillable, and the screens have to refuse all of them. */
it('takes no answers when it is shut or has nothing to ask', function (string $state) {
    $form = match ($state) {
        'stopped by hand' => formWithQuestion(['closed_at' => now()->subHour()])[0],
        'past its moment' => formWithQuestion(['closes_at' => now()->subHour()])[0],
        'without a question' => Form::factory()->create(),
    };

    expect($form->acceptsAnswers())->toBeFalse();
})->with(['stopped by hand', 'past its moment', 'without a question']);

it('finds the open ones in SQL as it does in PHP', function () {
    [$open] = formWithQuestion();
    formWithQuestion(['closed_at' => now()]);
    formWithQuestion(['closes_at' => now()->subMinute()]);
    [$later] = formWithQuestion(['closes_at' => now()->addDay()]);

    expect(Form::open()->pluck('id')->all())->toEqualCanonicalizing([$open->id, $later->id]);
});

it('keeps its questions in the order they were asked', function () {
    $form = Form::factory()->create();

    FormField::factory()->for($form)->at(1)->create(['key' => 'tweede', 'label' => 'Van wanneer?']);
    FormField::factory()->for($form)->at(0)->create(['key' => 'eerste', 'label' => 'Waarom?']);

    expect($form->fields()->pluck('key')->all())->toBe(['eerste', 'tweede']);
});

it('refuses the same key twice in one form', function () {
    [$form] = formWithQuestion();

    expect(fn () => FormField::factory()->for($form)->create(['key' => 'reden']))
        ->toThrow(QueryException::class);
});

it('lets two forms use the same key without minding', function () {
    [$first] = formWithQuestion();
    [$second] = formWithQuestion();

    expect($first->fields()->first()->key)->toBe('reden')
        ->and($second->fields()->first()->key)->toBe('reden');
});

it('has no public link until somebody hands one out', function () {
    [$form] = formWithQuestion();

    expect($form->isShared())->toBeFalse()
        ->and($form->publicUrl())->toBeNull();
});

it('builds the public address out of the token it handed out', function () {
    [$form] = formWithQuestion();

    $token = $form->share();

    expect($form->isShared())->toBeTrue()
        ->and($form->publicUrl())->toBe(route('forms.public.show', $token));
});

/** Sharing again is the only honest meaning of "withdraw and share once more". */
it('kills the old link when it hands out a new one', function () {
    [$form] = formWithQuestion();

    $first = $form->share();
    $second = $form->share();

    expect($second)->not->toBe($first)
        ->and(Form::query()->where('share_token', $first)->exists())->toBeFalse()
        ->and(Form::query()->where('share_token', $second)->exists())->toBeTrue();
});

it('leaves nothing behind to look a withdrawn link up by', function () {
    [$form] = formWithQuestion();
    $token = $form->share();

    $form->withdrawLink();

    expect($form->isShared())->toBeFalse()
        ->and($form->publicUrl())->toBeNull()
        ->and(Form::query()->where('share_token', $token)->exists())->toBeFalse();
});

/** The token is a secret, so it must not travel with the form to a screen. */
it('keeps the token out of anything the form is turned into', function () {
    [$form] = formWithQuestion();
    $form->share();

    expect($form->toArray())->not->toHaveKey('share_token');
});

it('knows whether somebody already filled it in', function () {
    [$form] = formWithQuestion();
    $filler = User::factory()->create();
    $other = User::factory()->create();

    FormSubmission::factory()->for($form)->create(['submitted_by' => $filler->id]);

    expect($form->hasSubmissionFrom($filler))->toBeTrue()
        ->and($form->hasSubmissionFrom($other))->toBeFalse();
});

it('does not count an anonymous submission as anybody having filled it in', function () {
    [$form] = formWithQuestion();
    $stranger = User::factory()->create();

    FormSubmission::factory()->for($form)->anonymous()->create();

    expect($form->hasSubmissionFrom($stranger))->toBeFalse();
});

it('makes a key out of a label', function (string $label, string $expected) {
    $form = Form::factory()->create();

    expect($form->keyFor($label))->toBe($expected);
})->with([
    'a word' => ['Reden', 'reden'],
    'a sentence' => ['Van wanneer tot wanneer', 'van_wanneer_tot_wanneer'],
    'accents' => ['Café bezoek', 'cafe_bezoek'],
    'nothing to go on' => ['', 'veld'],
    /*
     * A question is written as a question, and {{ trigger.answers.waarom? }} is
     * not a path a workflow can read — see ResolveVariables::PATTERN.
     */
    'a question mark' => ['Waarom?', 'waarom'],
    'punctuation in the middle' => ['E-mail & telefoon', 'e_mail_telefoon'],
]);

/**
 * Two boxes may be called the same thing on screen; the names workflows read
 * them by cannot be.
 */
it('gives a second question with the same label a key of its own', function () {
    [$form] = formWithQuestion([], ['key' => 'reden', 'label' => 'Reden']);

    expect($form->keyFor('Reden'))->toBe('reden_2');

    FormField::factory()->for($form)->create(['key' => 'reden_2', 'label' => 'Reden']);

    expect($form->refresh()->keyFor('Reden'))->toBe('reden_3');
});

it('keeps a key short enough to fit the column', function () {
    $form = Form::factory()->create();

    expect(strlen($form->keyFor(str_repeat('lange vraag ', 20))))->toBeLessThanOrEqual(50);
});

it('gives every new question a key of its own when the builder saves', function () {
    $form = Form::factory()->create();

    app(SaveFormFields::class)->handle($form, [
        ['type' => 'short-text', 'label' => 'Reden'],
        ['type' => 'short-text', 'label' => 'Reden'],
    ]);

    expect($form->fields()->pluck('key')->all())->toBe(['reden', 'reden_2']);
});

/** A workflow reads {{ trigger.answers.reden }}; renaming the box must not break it. */
it('leaves the key alone when a question is rewritten', function () {
    $form = Form::factory()->create();

    app(SaveFormFields::class)->handle($form, [['type' => 'short-text', 'label' => 'Reden']]);

    $field = $form->fields()->first();

    app(SaveFormFields::class)->handle($form, [[
        'id' => $field->id,
        'type' => 'long-text',
        'label' => 'Waarom vraag je dit aan?',
    ]]);

    expect($form->fields()->first())
        ->key->toBe('reden')
        ->label->toBe('Waarom vraag je dit aan?')
        ->type->toBe(FormFieldType::LongText);
});

it('drops the questions the builder left out', function () {
    $form = Form::factory()->create();

    app(SaveFormFields::class)->handle($form, [
        ['type' => 'short-text', 'label' => 'Reden'],
        ['type' => 'short-text', 'label' => 'Wanneer'],
    ]);

    $kept = $form->fields()->first();

    app(SaveFormFields::class)->handle($form, [['id' => $kept->id, 'type' => 'short-text', 'label' => 'Reden']]);

    expect($form->fields()->pluck('key')->all())->toBe(['reden']);
});

/**
 * The whole reason an answer carries its own copy of the question: the box it
 * was typed into may be gone by the time somebody reads it back.
 */
it('leaves an answer readable after its question was deleted', function () {
    [$form, $question] = formWithQuestion();
    $submission = FormSubmission::factory()->for($form)->create();

    $answer = FormAnswer::factory()->create([
        'form_submission_id' => $submission->id,
        'form_field_id' => $question->id,
        'field_key' => $question->key,
        'question' => $question->label,
        'type' => FormFieldType::ShortText,
        'value' => 'Twee weken zon.',
    ]);

    $question->delete();

    expect($answer->refresh())
        ->form_field_id->toBeNull()
        ->question->toBe('Waarom vraag je dit aan?')
        ->field_key->toBe('reden')
        ->and($answer->display())->toBe('Twee weken zon.');
});

it('reads every kind of answer back as a sentence', function () {
    $submission = FormSubmission::factory()->create();

    $answer = FormAnswer::factory()->for($submission, 'submission')->create([
        'type' => FormFieldType::Boolean,
        'value' => true,
    ]);

    expect($answer->display())->toBe('Ja');
});

it('hands a workflow the answers under the keys the form invented', function () {
    $submission = FormSubmission::factory()->create();

    FormAnswer::factory()->for($submission, 'submission')->create([
        'field_key' => 'reden',
        'type' => FormFieldType::ShortText,
        'value' => 'Twee weken zon.',
        'position' => 0,
    ]);
    FormAnswer::factory()->for($submission, 'submission')->create([
        'field_key' => 'dagen',
        'type' => FormFieldType::Number,
        'value' => 10,
        'position' => 1,
    ]);

    expect($submission->load('answers')->keyedAnswers())->toBe([
        'reden' => 'Twee weken zon.',
        'dagen' => '10',
    ]);
});

it('says out loud that nobody put their name to a submission', function () {
    $named = FormSubmission::factory()->create();
    $anonymous = FormSubmission::factory()->anonymous()->create();

    expect($named->isAnonymous())->toBeFalse()
        ->and($anonymous->isAnonymous())->toBeTrue()
        ->and($anonymous->via_link)->toBeTrue();
});

it('keeps the answers of somebody who has since left', function () {
    $leaver = User::factory()->create();
    $submission = FormSubmission::factory()->create(['submitted_by' => $leaver->id]);

    $leaver->delete();

    expect($submission->refresh()->submitted_by)->toBeNull()
        ->and($submission->isAnonymous())->toBeTrue();
});

/** A form outlives its author, the way a poll outlives its asker. */
it('outlives the person who wrote it', function () {
    $author = User::factory()->create();
    [$form] = formWithQuestion(['created_by' => $author->id]);

    $author->delete();

    expect($form->refresh()->created_by)->toBeNull();
});

it('takes its questions and everything filled in with it', function () {
    [$form] = formWithQuestion();
    $submission = FormSubmission::factory()->for($form)->create();
    FormAnswer::factory()->for($submission, 'submission')->create();

    $form->delete();

    expect(FormField::count())->toBe(0)
        ->and(FormSubmission::count())->toBe(0)
        ->and(FormAnswer::count())->toBe(0);
});

it('goes when the workspace it belongs to goes', function () {
    $workspace = Workspace::factory()->create();
    formWithQuestion(['workspace_id' => $workspace->id]);

    $workspace->delete();

    expect(Form::count())->toBe(0)
        ->and(FormField::count())->toBe(0);
});
