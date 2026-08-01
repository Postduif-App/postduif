<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;

/**
 * A webhook that pulls its text out of whatever the sender already sends.
 *
 * @return array{0: Webhook, 1: string}
 */
function pathWebhook(?string $path): array
{
    $user = User::factory()->create();
    $channel = channelWithMember(workspaceWithMember($user), $user);

    $webhook = Webhook::factory()->for($channel)->create([
        'bot_name' => 'Buildbot',
        'body_path' => $path,
    ]);

    $token = $webhook->regenerateToken();
    $webhook->save();

    return [$webhook, $token];
}

function postJsonToWebhook(string $token, array $payload)
{
    return test()->postJson(route('webhooks.messages.store', $token), $payload);
}

it('takes the text from the path it was given', function () {
    [$webhook, $token] = pathWebhook('issue.title');

    postJsonToWebhook($token, [
        'action' => 'opened',
        'issue' => ['title' => 'De deur klemt', 'number' => 12],
    ])->assertCreated();

    expect(Message::sole()->body)->toBe('De deur klemt');
});

/** The point of dot notation: how deep it goes is the sender's business. */
it('reaches into a list several levels down', function () {
    [, $token] = pathWebhook('payload.commits.0.message');

    postJsonToWebhook($token, [
        'payload' => [
            'commits' => [
                ['message' => 'Eerste commit'],
                ['message' => 'Tweede commit'],
            ],
        ],
    ])->assertCreated();

    expect(Message::sole()->body)->toBe('Eerste commit');
});

it('says something worth saying when the path leads nowhere', function () {
    [, $token] = pathWebhook('issue.title');

    postJsonToWebhook($token, ['something' => 'else'])
        ->assertStatus(422)
        // Named, so whoever set it up can see which path did not match.
        ->assertSee('issue.title');

    expect(Message::count())->toBe(0);
});

/**
 * A build number is a fine thing to say out loud. A list is not a message, and
 * posting "Array" or a blob of JSON would be sending something nobody meant.
 */
it('takes a number as text but refuses a list', function () {
    [, $token] = pathWebhook('build.number');

    postJsonToWebhook($token, ['build' => ['number' => 402]])->assertCreated();

    expect(Message::sole()->body)->toBe('402');

    [, $other] = pathWebhook('build.tags');

    postJsonToWebhook($other, ['build' => ['tags' => ['ci', 'main']]])
        ->assertStatus(422)
        ->assertSee('lijst');
});

it('takes a true or false as the word', function () {
    [, $token] = pathWebhook('deployed');

    postJsonToWebhook($token, ['deployed' => true])->assertCreated();

    expect(Message::sole()->body)->toBe('true');
});

it('refuses a path that leads to nothing but spaces', function () {
    [, $token] = pathWebhook('issue.title');

    postJsonToWebhook($token, ['issue' => ['title' => '   ']])->assertStatus(422);

    expect(Message::count())->toBe(0);
});

it('refuses a message longer than the limit', function () {
    [, $token] = pathWebhook('text');

    postJsonToWebhook($token, ['text' => str_repeat('a', 4001)])->assertStatus(422);

    expect(Message::count())->toBe(0);
});

/**
 * A webhook with no path keeps the contract it was set up with, which is why
 * this could ship without anybody having to change their integration.
 */
it('leaves a webhook without a path on the original contract', function () {
    [, $token] = pathWebhook(null);

    postJsonToWebhook($token, ['issue' => ['title' => 'Niet dit']])
        ->assertStatus(422)
        ->assertSee('text');

    postJsonToWebhook($token, ['text' => 'Maar dit wel'])->assertCreated();

    expect(Message::sole()->body)->toBe('Maar dit wel');
});

/** An unknown token must not be told what shape its payload should have been. */
it('answers an unknown token before looking at the payload at all', function () {
    test()->postJson(route('webhooks.messages.store', 'whk_bestaatniet'), [])
        ->assertNotFound();
});
