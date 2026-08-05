<?php

use App\Actions\Forms\SubmitForm;
use App\Enums\FormFieldType;
use App\Events\FormSubmitted;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * A form asking one thing, with somebody in the workspace to answer it.
 *
 * The field is handed in rather than defaulted, because nearly every test here
 * is about one particular kind of question.
 *
 * @return array{0: Form, 1: FormField, 2: User}
 */
function formAsking(FormField $question, array $state = []): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    $question->form_id = $form->id;
    $question->save();

    $filler = User::factory()->create();
    joinWorkspace($workspace, $filler);

    return [$form->refresh(), $question, $filler];
}

/** A question of some type, not yet attached to a form. */
function question(FormFieldType $type = FormFieldType::ShortText, array $state = []): FormField
{
    return FormField::factory()->ofType($type)->make([
        'key' => 'reden',
        'label' => 'Waarom vraag je dit aan?',
        ...$state,
    ]);
}

it('keeps what somebody typed, keyed the way the form asked it', function () {
    [$form, , $filler] = formAsking(question());

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Twee weken zon.']);

    expect($submission->form_id)->toBe($form->id)
        ->and($submission->submitted_by)->toBe($filler->id)
        ->and($submission->via_link)->toBeFalse()
        ->and($submission->answers)->toHaveCount(1)
        ->and($submission->answers->first()->field_key)->toBe('reden')
        ->and($submission->answers->first()->display())->toBe('Twee weken zon.');
});

/**
 * The answer carries the question, the key and the type of its own — which is
 * what keeps it readable once the box it was typed into is gone.
 */
it('copies the question onto the answer rather than pointing at it', function () {
    [$form, $question, $filler] = formAsking(question(FormFieldType::Boolean, ['label' => 'Met vakantiedagen?']));

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => true]);

    $question->delete();

    $answer = $submission->answers()->first();

    expect($answer->form_field_id)->toBeNull()
        ->and($answer->question)->toBe('Met vakantiedagen?')
        ->and($answer->field_key)->toBe('reden')
        ->and($answer->type)->toBe(FormFieldType::Boolean)
        ->and($answer->display())->toBe('Ja');
});

it('numbers the answers the way the questions were numbered', function () {
    [$form, , $filler] = formAsking(question());
    FormField::factory()->for($form)->at(1)->create(['key' => 'dagen', 'label' => 'Hoeveel dagen?']);

    $submission = app(SubmitForm::class)->handle($form, $filler, [
        'reden' => 'Zon',
        'dagen' => 'Tien',
    ]);

    expect($submission->answers()->pluck('field_key')->all())->toBe(['reden', 'dagen'])
        ->and($submission->answers()->pluck('position')->all())->toBe([0, 1]);
});

it('takes every kind of answer in and reads it back out', function (FormFieldType $type, mixed $sent, string $read) {
    [$form, , $filler] = formAsking(question($type, [
        'options' => $type->takesOptions() ? ['Ja', 'Nee', 'Misschien'] : [],
    ]));

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => $sent]);

    expect($submission->answers()->first()->display())->toBe($read);
})->with([
    'korte tekst' => [FormFieldType::ShortText, 'Twee weken zon.', 'Twee weken zon.'],
    'lange tekst' => [FormFieldType::LongText, "Eerst dit.\nDan dat.", "Eerst dit.\nDan dat."],
    'één keuze' => [FormFieldType::Choice, 'Misschien', 'Misschien'],
    'meerdere keuzes' => [FormFieldType::MultipleChoice, ['Ja', 'Misschien'], 'Ja, Misschien'],
    'een rond getal' => [FormFieldType::Number, '10', '10'],
    'een getal met komma' => [FormFieldType::Number, '2.5', '2,5'],
    'een datum' => [FormFieldType::Date, '2026-08-05', '2026-08-05'],
    'ja' => [FormFieldType::Boolean, true, 'Ja'],
    'nee' => [FormFieldType::Boolean, false, 'Nee'],
]);

/** A box left empty is an answer too, and it has to read as one. */
it('writes a dash where an optional question was skipped', function (FormFieldType $type, mixed $sent) {
    [$form, , $filler] = formAsking(question($type, [
        'required' => false,
        'options' => $type->takesOptions() ? ['Ja', 'Nee'] : [],
    ]));

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => $sent]);

    expect($submission->answers()->first()->display())->toBe('—');
})->with([
    'niets getypt' => [FormFieldType::ShortText, ''],
    'niets gekozen' => [FormFieldType::MultipleChoice, []],
    'geen getal' => [FormFieldType::Number, ''],
    'helemaal overgeslagen' => [FormFieldType::ShortText, null],
]);

it('records a question nobody sent an answer for at all', function () {
    [$form, , $filler] = formAsking(question(state: ['required' => false]));
    FormField::factory()->for($form)->optional()->at(1)->create(['key' => 'dagen', 'label' => 'Hoeveel dagen?']);

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon']);

    expect($submission->answers()->count())->toBe(2)
        ->and($submission->answers()->where('field_key', 'dagen')->first()->display())->toBe('—');
});

it('lets somebody through the shared link answer without a name', function () {
    [$form] = formAsking(question(), ['share_token' => Str::random(48)]);

    $submission = app(SubmitForm::class)->handle($form, null, ['reden' => 'Zon'], viaLink: true);

    expect($submission->submitted_by)->toBeNull()
        ->and($submission->via_link)->toBeTrue()
        ->and($submission->isAnonymous())->toBeTrue();
});

it('notes that a member came in over the link rather than over the card', function () {
    [$form, , $filler] = formAsking(question());

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon'], viaLink: true);

    expect($submission->submitted_by)->toBe($filler->id)
        ->and($submission->via_link)->toBeTrue()
        ->and($submission->isAnonymous())->toBeFalse();
});

it('refuses a form that cannot be filled in, whichever way it got that way', function (string $state) {
    [$form, $question, $filler] = formAsking(question());

    match ($state) {
        'stopped by hand' => $form->forceFill(['closed_at' => now()->subHour()])->save(),
        'past its moment' => $form->forceFill(['closes_at' => now()->subHour()])->save(),
        'without a question' => $question->delete(),
    };

    expect(fn () => app(SubmitForm::class)->handle($form->refresh(), $filler, ['reden' => 'Zon']))
        ->toThrow(RuntimeException::class);

    expect(FormSubmission::count())->toBe(0);
})->with(['stopped by hand', 'past its moment', 'without a question']);

/** Nothing half-written: the submission and its answers are one act. */
it('leaves nothing behind when it refuses', function () {
    [$form, , $filler] = formAsking(question(), ['closed_at' => now()]);

    try {
        app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon']);
    } catch (RuntimeException) {
        // The refusal is the subject of the test above; here it is the rows.
    }

    expect(FormSubmission::count())->toBe(0)
        ->and(FormAnswer::count())->toBe(0);
});

it('announces a submission once it is safe in the database', function () {
    Event::fake([FormSubmitted::class]);

    [$form, , $filler] = formAsking(question());

    $submission = app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon']);

    Event::assertDispatched(
        FormSubmitted::class,
        fn (FormSubmitted $event): bool => $event->submission->is($submission)
            && $event->submission->answers->count() === 1,
    );
});

it('lets the same person fill a form in again when the form says they may', function () {
    [$form, , $filler] = formAsking(question(), ['allows_multiple_submissions' => true]);

    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Zon']);
    app(SubmitForm::class)->handle($form, $filler, ['reden' => 'Nog meer zon']);

    expect($form->submissions()->count())->toBe(2);
});

it('asks for what is required and allows what is not', function () {
    [$form] = formAsking(question());
    FormField::factory()->for($form)->optional()->at(1)->create(['key' => 'dagen', 'label' => 'Hoeveel dagen?']);

    $rules = app(SubmitForm::class)->rulesFor($form->refresh());

    expect($rules['answers'])->toBe(['required', 'array'])
        ->and($rules['answers.reden'])->toBe(['required', 'string', 'max:500'])
        ->and($rules['answers.dagen'])->toBe(['nullable', 'string', 'max:500']);
});

it('holds a choice to the choices that were offered', function () {
    [$form] = formAsking(question(FormFieldType::Choice, ['options' => ['Ja', 'Nee']]));

    $rules = app(SubmitForm::class)->rulesFor($form);

    expect($rules['answers.reden'])->toBe(['required', 'string', 'in:Ja,Nee']);
});

it('checks every tick of a multiple-choice answer on its own', function () {
    [$form] = formAsking(question(FormFieldType::MultipleChoice, ['options' => ['Ja', 'Nee']]));

    $rules = app(SubmitForm::class)->rulesFor($form);

    expect($rules['answers.reden'])->toBe(['required', 'array'])
        ->and($rules['answers.reden.*'])->toBe(['string', 'in:Ja,Nee']);
});

/** An unticked box arrives as false, which is an answer and not a gap. */
it('never calls a yes-or-no question missing', function () {
    [$form] = formAsking(question(FormFieldType::Boolean));

    $rules = app(SubmitForm::class)->rulesFor($form);

    expect($rules['answers.reden'])->toBe(['nullable', 'boolean'])
        ->and(Validator::make(['answers' => ['reden' => false]], $rules)->passes())->toBeTrue();
});

it('refuses an answer that was never on the list', function () {
    [$form] = formAsking(question(FormFieldType::Choice, ['options' => ['Ja', 'Nee']]));

    $validator = Validator::make(
        ['answers' => ['reden' => 'Misschien']],
        app(SubmitForm::class)->rulesFor($form),
    );

    expect($validator->fails())->toBeTrue();
});

/** Somebody was looking at a box with a label on it, not at a field key. */
it('complains about the question rather than about the key', function () {
    [$form] = formAsking(question());

    $messages = app(SubmitForm::class)->messagesFor($form);

    expect($messages['answers.reden.required'])->toContain('Waarom vraag je dit aan?')
        ->and($messages['answers.reden.required'])->not->toContain('answers.reden');
});

it('starts a fill screen off in the shape every answer will come back in', function () {
    [$form] = formAsking(question());

    FormField::factory()->for($form)->ofType(FormFieldType::Boolean)->at(1)->create(['key' => 'dagen']);
    FormField::factory()->for($form)->choice(['Ja', 'Nee'], multiple: true)->at(2)->create(['key' => 'wie']);

    expect(app(SubmitForm::class)->blankAnswers($form->refresh()))->toBe([
        'reden' => '',
        'dagen' => false,
        'wie' => [],
    ]);
});
