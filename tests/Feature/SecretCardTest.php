<?php

use App\Models\Message;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\SecretValue;
use App\Models\Workspace;

/**
 * A message carrying a link to a request from the same workspace.
 *
 * @return array{0: Message, 1: SecretRequest, 2: SecretRequestKey}
 */
function messageWithSecretLink(array $state = []): array
{
    [$request, $password] = fillableRequest($state);

    $message = Message::factory()->create([
        'workspace_id' => $request->workspace_id,
        'channel_id' => $request->channel_id,
        'user_id' => $request->created_by,
        'body' => $request->title.' '.route('secrets.show', $request->id),
    ]);

    return [$message, $request, $password];
}

it('says what is being asked instead of showing a bare link', function () {
    [$message, $request] = messageWithSecretLink();

    expect(present($message)['secretCard'])
        ->title->toBe($request->title)
        ->keyCount->toBe(2)
        ->answeredCount->toBe(0)
        ->state->toBe('open');
});

it('counts the answers as they come in', function () {
    [$message, , $password] = messageWithSecretLink();

    SecretValue::record($password, 'geheim', null);

    expect(present($message)['secretCard']['answeredCount'])->toBe(1);
});

/**
 * Counts only. Which key was answered by whom would be announcing who holds
 * which credential to everybody in the channel.
 */
it('says nothing about who answered what', function () {
    [$message, , $password] = messageWithSecretLink();

    SecretValue::record($password, 'hunter2-geheim', null);

    $card = present($message)['secretCard'];

    expect(array_keys($card))->not->toContain('values')
        ->and(json_encode($card))->not->toContain('hunter2-geheim')
        ->and(json_encode($card))->not->toContain('DB_PASSWORD');
});

it('shows in the channel that a request has closed', function (array $state, string $expected) {
    [$message] = messageWithSecretLink($state);

    expect(present($message)['secretCard']['state'])->toBe($expected);
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'expired'],
    'withdrawn' => [['revoked_at' => now()->subHour()], 'revoked'],
]);

it('leaves an ordinary message alone', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Kijk eens op https://voorbeeld.nl',
    ]);

    expect(present($message)['secretCard'])->toBeNull();
});

/** A link pasted from elsewhere is somebody else's question. */
it('does not draw a request from another workspace', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    $elsewhere = Workspace::factory()->create();
    $stranger = SecretRequest::factory()->create(['workspace_id' => $elsewhere->id]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => route('secrets.show', $stranger->id),
    ]);

    expect(present($message)['secretCard'])->toBeNull();
});

it('draws nothing under a deleted message', function () {
    [$message] = messageWithSecretLink();

    $message->delete();

    expect(present($message->refresh())['secretCard'])->toBeNull();
});

/**
 * One link for everybody. Where it lands is the server's decision, because this
 * card is drawn once and broadcast to the whole channel — see the redirect test
 * in SecretFillTest.
 */
it('gives everybody in the channel the same link', function () {
    [$message, $request] = messageWithSecretLink();

    expect(present($message)['secretCard'])
        ->url->toBe(route('secrets.show', $request->id))
        ->not->toHaveKey('answersUrl');
});
