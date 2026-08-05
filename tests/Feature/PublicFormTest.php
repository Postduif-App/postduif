<?php

use App\Features\Forms;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A form handed to the world, and the token that is the whole permission.
 *
 * @return array{0: Form, 1: string}
 */
function sharedForm(array $state = []): array
{
    Queue::fake();

    [$author, $workspace] = formAuthor();

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    FormField::factory()->for($form)->create(['key' => 'reden', 'label' => 'Waarom?']);

    return [$form, $form->share()];
}

it('lets somebody with no account at all read the questions', function () {
    [$form, $token] = sharedForm();

    $this->get(route('forms.public.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('forms/public')
            ->has('form.fields', 1)
            ->where('anonymous', true)
            ->where('token', $token));
});

it('takes an answer from somebody with no account and keeps no name', function () {
    [$form, $token] = sharedForm();

    $this->post(route('forms.public.submit', $token), [
        'answers' => ['reden' => 'Ik kom van buiten'],
    ])->assertRedirect();

    $submission = FormSubmission::sole();

    expect($submission->submitted_by)->toBeNull()
        ->and($submission->via_link)->toBeTrue()
        ->and($submission->isAnonymous())->toBeTrue()
        ->and($submission->keyedAnswers()['reden'])->toBe('Ik kom van buiten');
});

it('keeps its promise even when a member happens to be signed in', function () {
    [$form, $token] = sharedForm();

    $member = User::factory()->create();
    joinWorkspace($form->workspace, $member);

    actingAs($member)
        ->post(route('forms.public.submit', $token), ['answers' => ['reden' => 'Toch anoniem']])
        ->assertRedirect();

    // The page said "je naam wordt niet meegestuurd", and that has to hold for
    // everybody who read it.
    expect(FormSubmission::sole()->submitted_by)->toBeNull();
});

it('validates the answers the same way the inside door does', function () {
    [, $token] = sharedForm();

    $this->post(route('forms.public.submit', $token), ['answers' => ['reden' => '']])
        ->assertSessionHasErrors('answers.reden');

    expect(FormSubmission::count())->toBe(0);
});

it('says nothing at all about a link that does not work', function (callable $break) {
    [$form, $token] = sharedForm();

    $break($form);

    $this->get(route('forms.public.show', $token))->assertNotFound();

    $this->post(route('forms.public.submit', $token), ['answers' => ['reden' => 'Hoi']])
        ->assertNotFound();

    expect(FormSubmission::count())->toBe(0);
})->with([
    'withdrawn' => [fn (Form $form) => $form->withdrawLink()],
    'shared again, so this one is the old address' => [fn (Form $form) => $form->share()],
    'the workspace switched forms off' => [
        fn (Form $form) => Feature::for($form->workspace)->deactivate(Forms::class),
    ],
]);

it('answers 404 for a token nobody ever handed out', function () {
    $this->get(route('forms.public.show', 'volstrekt-verzonnen-token'))->assertNotFound();
});

it('takes nothing once the form is shut', function (array $state) {
    [$form, $token] = sharedForm($state);

    $this->post(route('forms.public.submit', $token), ['answers' => ['reden' => 'Hoi']])
        ->assertNotFound();

    expect(FormSubmission::count())->toBe(0);
})->with([
    'stopped by hand' => [['closed_at' => now()->subHour()]],
    'moment passed' => [['closes_at' => now()->subHour()]],
]);

it('tells a stranger nothing about the workspace behind the form', function () {
    [$form, $token] = sharedForm();

    $this->get(route('forms.public.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A name to say who is asking, and not one identifier more.
            ->has('form.author')
            ->missing('form.workspaceId')
            ->missing('form.shareToken')
            ->missing('workspace'));
});

it('is throttled on the way in', function () {
    [, $token] = sharedForm();

    foreach (range(1, 10) as $ignored) {
        $this->post(route('forms.public.submit', $token), ['answers' => ['reden' => 'Hoi']]);
    }

    $this->post(route('forms.public.submit', $token), ['answers' => ['reden' => 'Hoi']])
        ->assertStatus(429);
});
