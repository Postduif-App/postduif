<?php

use App\Actions\Forms\SubmitForm;
use App\Enums\ChannelType;
use App\Enums\FormFieldType;
use App\Events\FormSubmitted;
use App\Features\Forms;
use App\Listeners\SendFormAnswers;
use App\Models\Channel;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * A form with two questions on it, the second one optional, and somebody other
 * than its author to fill it in.
 *
 * The optional question is part of the fixture rather than an extra step: a
 * question left empty still has to appear in the message, and that is the one
 * thing about this listener nobody would think to check by hand.
 *
 * @return array{0: User, 1: User, 2: Form, 3: Workspace}
 */
function formToFillIn(array $state = []): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);

    Feature::for($workspace)->activate(Forms::class);

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Vakantieaanvraag',
        ...$state,
    ]);

    FormField::factory()->for($form)->at(0)->create([
        'key' => 'reden',
        'label' => 'Waarom vraag je dit aan?',
    ]);
    FormField::factory()->for($form)->optional()->at(1)->create([
        'key' => 'dagen',
        'label' => 'Hoeveel dagen?',
    ]);

    $filler = User::factory()->create();
    joinWorkspace($workspace, $filler);

    return [$author, $filler, $form->refresh(), $workspace];
}

/** What the filler typed, with the optional question left alone. */
function answersGiven(): array
{
    return ['reden' => 'Twee weken zon.', 'dagen' => ''];
}

it('brings the answers to the author in the conversation with whoever filled it in', function () {
    [$author, $filler, $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, $filler, answersGiven());

    $message = Message::query()->latest('id')->first();
    $channel = $message->channel;

    expect($channel->type)->toBe(ChannelType::Direct)
        ->and($channel->members()->pluck('users.id')->all())->toEqualCanonicalizing([$author->id, $filler->id])
        // Posted by the form, not by the person who filled it in.
        ->and($message->user_id)->toBeNull()
        ->and($message->bot_name)->toBe('Vakantieaanvraag');
});

it('says who filled it in and repeats every question, empty ones included', function () {
    [, $filler, $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, $filler, answersGiven());

    expect(Message::query()->latest('id')->first()->body)
        ->toContain($filler->name)
        ->toContain('Vakantieaanvraag')
        ->toContain('Waarom vraag je dit aan?')
        ->toContain('Twee weken zon.')
        // The skipped question is still a line, with a dash for an answer.
        ->toContain('Hoeveel dagen?')
        ->toContain('—');
});

it('mentions the shared link when a member came in that way', function () {
    [, $filler, $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, $filler, answersGiven(), viaLink: true);

    expect(Message::query()->latest('id')->first()->body)->toContain('Via de gedeelde link');
});

it('sends an anonymous submission to the channel the author named for it', function () {
    [$author, , $form, $workspace] = formToFillIn();
    $channel = channelWithMember($workspace, $author);
    $form->update(['notify_channel_id' => $channel->id]);

    app(SubmitForm::class)->handle($form->refresh(), null, answersGiven(), viaLink: true);

    $message = Message::query()->latest('id')->first();

    expect($message->channel_id)->toBe($channel->id)
        ->and($message->bot_name)->toBe('Vakantieaanvraag')
        // No name to give, and the message says so rather than leaving a gap.
        ->and($message->body)->toContain('Er kwam een ingevuld "Vakantieaanvraag" binnen')
        ->and($message->body)->toContain('Twee weken zon.');
});

/** Nowhere to put it is a shrug, not a failure. */
it('says nothing at all when an anonymous submission has nowhere to land', function () {
    [, , $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, null, answersGiven(), viaLink: true);

    expect(Message::count())->toBe(0);
});

it('falls back to the named channel when the author fills in their own form', function () {
    [$author, , $form, $workspace] = formToFillIn();
    $channel = channelWithMember($workspace, $author);
    $form->update(['notify_channel_id' => $channel->id]);

    app(SubmitForm::class)->handle($form->refresh(), $author, answersGiven());

    $message = Message::query()->latest('id')->first();

    expect($message->channel_id)->toBe($channel->id)
        ->and($message->body)->toContain($author->name);
});

it('keeps quiet when the author fills in their own form and named no channel', function () {
    [$author, , $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, $author, answersGiven());

    expect(Message::count())->toBe(0);
});

it('has nobody to tell once the author has left', function () {
    [$author, $filler, $form] = formToFillIn();

    $author->delete();

    app(SubmitForm::class)->handle($form->refresh(), $filler, answersGiven());

    expect(Message::count())->toBe(0);
});

/** Answers must never surface in a workspace whose members were never asked. */
it('refuses to deliver into a channel from another workspace', function () {
    [, , $form] = formToFillIn();
    $elsewhere = Channel::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $form->update(['notify_channel_id' => $elsewhere->id]);

    app(SubmitForm::class)->handle($form->refresh(), null, answersGiven(), viaLink: true);

    expect(Message::count())->toBe(0);
});

it('leaves an archived channel alone, because nobody reads it', function () {
    [$author, , $form, $workspace] = formToFillIn();
    $channel = channelWithMember($workspace, $author);
    $channel->forceFill(['archived_at' => now()])->save();
    $form->update(['notify_channel_id' => $channel->id]);

    app(SubmitForm::class)->handle($form->refresh(), null, answersGiven(), viaLink: true);

    expect(Message::count())->toBe(0);
});

/** One submission is one message: the DM and the channel never both fire. */
it('delivers a named submission once and only to the conversation', function () {
    [$author, $filler, $form, $workspace] = formToFillIn();
    $channel = channelWithMember($workspace, $author);
    $form->update(['notify_channel_id' => $channel->id]);

    app(SubmitForm::class)->handle($form->refresh(), $filler, answersGiven());

    expect(Message::count())->toBe(1)
        ->and($channel->messages()->count())->toBe(0);
});

/**
 * The message goes to the person who wrote the questions, so it is written in
 * their language — not in the language of whoever happened to fill it in.
 */
it('writes to the author in the author his own language', function () {
    [$author, $filler, $form] = formToFillIn();
    $author->update(['locale' => 'en']);
    $filler->update(['locale' => 'nl']);

    app(SubmitForm::class)->handle($form, $filler, [
        'reden' => 'Two sunny weeks.',
        'dagen' => '',
    ]);

    expect(Message::query()->latest('id')->first()->body)
        ->toContain('filled in')
        ->toContain('All the answers are with the form itself');
});

it('falls back to the language of the application when the author never chose one', function () {
    [$author, $filler, $form] = formToFillIn();
    $author->update(['locale' => null]);

    app(SubmitForm::class)->handle($form, $filler, answersGiven());

    expect(Message::query()->latest('id')->first()->body)->toContain('vulde');
});

it('reads a yes or no back as a word in the author his language', function () {
    [$author, $filler, $form] = formToFillIn();
    $author->update(['locale' => 'en']);
    FormField::factory()->for($form)->ofType(FormFieldType::Boolean)->at(2)->create([
        'key' => 'dagen_op',
        'label' => 'With holiday allowance?',
    ]);

    app(SubmitForm::class)->handle($form->refresh(), $filler, [...answersGiven(), 'dagen_op' => true]);

    expect(Message::query()->latest('id')->first()->body)->toContain('With holiday allowance?**: Yes');
});

/** A title has room to be a sentence; the line above a message does not. */
it('cuts a long form title down to something a sender line can hold', function () {
    $title = 'Aanvraag voor verlof, onbetaald verlof of ouderschapsverlof in het komende kalenderjaar';
    [, $filler, $form] = formToFillIn(['title' => $title]);

    app(SubmitForm::class)->handle($form, $filler, answersGiven());

    expect(Message::query()->latest('id')->first()->bot_name)
        ->toBe(Str::limit($title, 60))
        ->and(mb_strlen(Message::query()->latest('id')->first()->bot_name))->toBeLessThan(mb_strlen($title));
});

/**
 * The listener is queued and waits for the commit, so nothing is sent from
 * inside the transaction that wrote the answers.
 */
it('leaves the message to a worker rather than making somebody wait for it', function () {
    Queue::fake();

    [, $filler, $form] = formToFillIn();

    app(SubmitForm::class)->handle($form, $filler, answersGiven());

    expect(Message::count())->toBe(0);

    $listener = app(SendFormAnswers::class);

    expect((new ReflectionClass($listener))->getProperty('afterCommit')->getValue($listener))->toBeTrue()
        ->and((new ReflectionClass($listener))->getProperty('tries')->getValue($listener))->toBe(1);
});

/** Picked up off the queue, with nothing loaded in hand, it still says it all. */
it('reads everything it needs back from the database', function () {
    Queue::fake();

    [, $filler, $form] = formToFillIn();

    $submission = app(SubmitForm::class)->handle($form, $filler, answersGiven());

    app(SendFormAnswers::class)->handle(new FormSubmitted($submission->fresh()));

    expect(Message::query()->latest('id')->first()->body)
        ->toContain('Waarom vraag je dit aan?')
        ->toContain('Hoeveel dagen?');
});
