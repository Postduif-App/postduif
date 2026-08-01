<?php

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Event;

/**
 * A webhook pointed at a channel with one member, and the plain token to call
 * it with — which exists nowhere else, since only the hash is stored.
 *
 * @return array{0: Webhook, 1: string}
 */
function webhookWithToken(?Channel $channel = null): array
{
    if ($channel === null) {
        $user = User::factory()->create();
        $channel = channelWithMember(workspaceWithMember($user), $user);
    }

    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);
    $token = $webhook->regenerateToken();
    $webhook->save();

    return [$webhook, $token];
}

function postToWebhook(string $token, array $payload = ['text' => 'De build is groen'])
{
    return test()->postJson(route('webhooks.messages.store', $token), $payload);
}

it('posts a message into the channel the webhook belongs to', function () {
    [$webhook, $token] = webhookWithToken();

    $response = postToWebhook($token)->assertCreated();

    $message = Message::findOrFail($response->json('id'));

    expect($message->channel_id)->toBe($webhook->channel_id)
        ->and($message->body)->toBe('De build is groen')
        ->and($message->bot_name)->toBe('Buildbot')
        ->and($message->user_id)->toBeNull();
});

it('broadcasts the message like any other', function () {
    Event::fake([MessageSent::class]);

    [, $token] = webhookWithToken();

    postToWebhook($token)->assertCreated();

    Event::assertDispatched(MessageSent::class);
});

it('records when a webhook was last used', function () {
    [$webhook, $token] = webhookWithToken();

    expect($webhook->last_used_at)->toBeNull();

    postToWebhook($token)->assertCreated();

    expect($webhook->fresh()->last_used_at)->not->toBeNull();
});

it('needs no CSRF token or session', function () {
    [, $token] = webhookWithToken();

    // Nothing signs in, and no token is primed. A route inside the web group
    // would answer 419 here.
    postToWebhook($token)->assertCreated();
});

it('turns down an unknown token', function () {
    webhookWithToken();

    postToWebhook('whk_ditbestaatniet')->assertNotFound();
});

/**
 * Revoked answers the same as unknown. Anything more specific would tell a
 * caller that the token they hold used to work here.
 */
it('turns down a revoked token, indistinguishably from an unknown one', function () {
    [$webhook, $token] = webhookWithToken();

    $webhook->forceFill(['revoked_at' => now()])->save();

    postToWebhook($token)->assertNotFound();
    expect(Message::count())->toBe(0);
});

it('turns down a token that is nearly right', function () {
    [, $token] = webhookWithToken();

    postToWebhook($token.'x')->assertNotFound();
});

it('refuses to post into an archived channel', function () {
    $user = User::factory()->create();
    $channel = channelWithMember(workspaceWithMember($user), $user);
    [, $token] = webhookWithToken($channel);

    $channel->forceFill(['archived_at' => now()])->save();

    postToWebhook($token)->assertStatus(422);
    expect(Message::count())->toBe(0);
});

it('answers in JSON when the payload has no text', function () {
    [, $token] = webhookWithToken();

    postToWebhook($token, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('turns down a body that is too long', function () {
    [, $token] = webhookWithToken();

    postToWebhook($token, ['text' => str_repeat('a', 4001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('stops a webhook that keeps firing', function () {
    [, $token] = webhookWithToken();

    foreach (range(1, 60) as $ignored) {
        postToWebhook($token)->assertCreated();
    }

    postToWebhook($token)->assertStatus(429);
});

/**
 * The budget belongs to the token, not to the caller's address — one busy
 * integration must not be able to lock the others out.
 */
it('gives every webhook its own budget', function () {
    [, $first] = webhookWithToken();
    [, $second] = webhookWithToken();

    foreach (range(1, 60) as $ignored) {
        postToWebhook($first)->assertCreated();
    }

    postToWebhook($first)->assertStatus(429);
    postToWebhook($second)->assertCreated();
});
