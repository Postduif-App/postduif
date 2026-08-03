<?php

use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use App\Models\Workspace;

/**
 * A message carrying a link to a poll in the same workspace.
 *
 * @return array{0: Message, 1: Poll, 2: PollOption, 3: PollOption, 4: User}
 */
function messageWithPollLink(array $state = []): array
{
    [$asker, $workspace, $channel] = pollChannel();

    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $asker->id,
        ...$state,
    ]);

    $dinsdag = PollOption::factory()->create([
        'poll_id' => $poll->id,
        'label' => 'Dinsdag',
        'position' => 0,
    ]);
    $woensdag = PollOption::factory()->create([
        'poll_id' => $poll->id,
        'label' => 'Woensdag',
        'position' => 1,
    ]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $asker->id,
        'body' => $poll->question.' '.route('chat.polls.show', [$workspace->slug, $poll->id]),
    ]);

    return [$message, $poll->refresh(), $dinsdag, $woensdag, $asker];
}

it('draws the question and its answers', function () {
    [$message, $poll] = messageWithPollLink();

    expect(present($message)['pollCard'])
        ->question->toBe($poll->question)
        ->state->toBe('open')
        ->isClosed->toBeFalse()
        ->voterCount->toBe(0);

    expect(collect(present($message)['pollCard']['options'])->pluck('label')->all())
        ->toBe(['Dinsdag', 'Woensdag']);
});

/**
 * Who voted travels with the card. Two reasons: a vote here is not anonymous,
 * and this payload is broadcast to everybody at once — so "what you chose"
 * cannot be in it and the browser works it out from this list.
 */
it('says who voted for what', function () {
    [$message, , $dinsdag] = messageWithPollLink();
    $voter = User::factory()->create(['name' => 'Fenna']);

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $voter->id]);

    $card = present($message)['pollCard'];

    expect($card['options'][0]['voters'])->toHaveCount(1)
        ->and($card['options'][0]['voters'][0]['id'])->toBe($voter->id)
        ->and($card['options'][0]['voters'][0]['name'])->toBe('Fenna')
        ->and($card['voterCount'])->toBe(1);
});

/** The stack beside an answer needs faces, not just names. */
it('carries a face for each voter', function () {
    [$message, , $dinsdag] = messageWithPollLink();
    $withPicture = User::factory()->create(['avatar_path' => 'avatars/fenna.jpg']);
    $without = User::factory()->create(['avatar_path' => null]);

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $withPicture->id]);
    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $without->id]);

    $voters = collect(present($message)['pollCard']['options'][0]['voters'])
        ->keyBy('id');

    expect($voters[$withPicture->id]['avatarUrl'])
        ->toBe(route('avatars.user', $withPicture))
        ->and($voters[$without->id]['avatarUrl'])->toBeNull();
});

/** People, not ticks: one person under two answers is still one person. */
it('counts people rather than ticks', function () {
    [$message, , $dinsdag, $woensdag] = messageWithPollLink(['allows_multiple' => true]);
    $voter = User::factory()->create();

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $voter->id]);
    PollVote::create(['poll_option_id' => $woensdag->id, 'user_id' => $voter->id]);

    expect(present($message)['pollCard']['voterCount'])->toBe(1);
});

/** Two ways to be shut, and the channel should be able to read the difference. */
it('says which kind of closed it is', function (array $state, string $expected) {
    [$message] = messageWithPollLink($state);

    expect(present($message)['pollCard'])
        ->state->toBe($expected)
        ->isClosed->toBeTrue();
})->with([
    'stopped by hand' => [['closed_at' => now()->subHour()], 'closed'],
    'moment passed' => [['closes_at' => now()->subHour()], 'expired'],
]);

/**
 * Whose poll it is travels along for the same reason the voters do: the card
 * is broadcast to everybody at once, so the browser decides who gets the close
 * and reopen buttons.
 */
it('says who asked the question', function () {
    [$message, , , , $asker] = messageWithPollLink();

    expect(present($message)['pollCard']['askedBy'])->toBe($asker->id);
});

it('leaves an ordinary message alone', function () {
    [$user, $workspace, $channel] = pollChannel();

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Kijk eens op https://voorbeeld.nl',
    ]);

    expect(present($message)['pollCard'])->toBeNull();
});

/** A link pasted from elsewhere is somebody else's question. */
it('does not draw a poll from another workspace', function () {
    [$user, $workspace, $channel] = pollChannel();

    $elsewhere = Workspace::factory()->create();
    $stranger = Poll::factory()->create(['workspace_id' => $elsewhere->id]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => route('chat.polls.show', [$elsewhere->slug, $stranger->id]),
    ]);

    expect(present($message)['pollCard'])->toBeNull();
});

it('draws nothing under a deleted message', function () {
    [$message] = messageWithPollLink();

    $message->delete();

    expect(present($message->refresh())['pollCard'])->toBeNull();
});
