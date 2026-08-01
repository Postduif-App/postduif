<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('stores a token in both forms and hands the plain value back', function () {
    $webhook = Webhook::factory()->create();

    $token = $webhook->regenerateToken();
    $webhook->save();

    expect($token)->toStartWith('whk_')
        ->and($webhook->token_hash)->not->toBe($token)
        ->and($webhook->token_hash)->toBe(hash('sha256', $token))
        // The hash is what the endpoint looks up; the encrypted copy is what
        // lets somebody see their own URL again later.
        ->and($webhook->fresh()->token)->toBe($token);

    // Neither form may reach a payload by accident. Showing the URL is a
    // deliberate call to url(), never a model serialisation.
    expect($webhook->fresh()->toArray())
        ->not->toHaveKey('token_hash')
        ->not->toHaveKey('token');
});

it('keeps the stored token unreadable in the database', function () {
    $webhook = Webhook::factory()->create();

    $token = $webhook->regenerateToken();
    $webhook->save();

    $stored = DB::table('webhooks')->where('id', $webhook->id)->value('token');

    expect($stored)->not->toBe($token)
        ->and($stored)->not->toContain('whk_')
        ->and(Crypt::decryptString($stored))->toBe($token);
});

it('builds the posting url from the stored token', function () {
    $webhook = Webhook::factory()->create();

    $token = $webhook->regenerateToken();
    $webhook->save();

    expect($webhook->fresh()->url())->toBe(route('webhooks.messages.store', $token));
});

/**
 * Webhooks made before the token was kept have nothing to rebuild a URL from,
 * and a revoked one has no working URL at all — offering the dead string would
 * invite somebody to paste it into an integration that then never posts.
 */
it('has no url for a webhook whose token was never stored', function () {
    expect(Webhook::factory()->create()->url())->toBeNull();
});

it('has no url for a revoked webhook', function () {
    $webhook = Webhook::factory()->create();
    $webhook->regenerateToken();
    $webhook->forceFill(['revoked_at' => now()])->save();

    expect($webhook->fresh()->url())->toBeNull();
});

it('replaces the url when a new token is minted', function () {
    $webhook = Webhook::factory()->create();
    $webhook->regenerateToken();
    $webhook->save();

    $before = $webhook->url();

    $webhook->regenerateToken();
    $webhook->save();

    expect($webhook->fresh()->url())->not->toBe($before);
});

it('finds a webhook back by the hash of its token', function () {
    $webhook = Webhook::factory()->create();
    $token = $webhook->regenerateToken();
    $webhook->save();

    expect(Webhook::where('token_hash', Webhook::hashToken($token))->first()?->id)
        ->toBe($webhook->id)
        ->and(Webhook::where('token_hash', Webhook::hashToken('whk_wrong'))->exists())
        ->toBeFalse();
});

it('mints a different token every time', function () {
    $webhook = Webhook::factory()->create();

    expect($webhook->regenerateToken())->not->toBe($webhook->regenerateToken());
});

it('lifts a revocation when a new token is minted', function () {
    $webhook = Webhook::factory()->revoked()->create();

    expect($webhook->isRevoked())->toBeTrue();

    $webhook->regenerateToken();

    expect($webhook->isRevoked())->toBeFalse();
});

it('leaves revoked webhooks out of the active scope', function () {
    $channel = Channel::factory()->create();
    $active = Webhook::factory()->for($channel)->create();
    Webhook::factory()->for($channel)->revoked()->create();

    expect(Webhook::active()->pluck('id')->all())->toBe([$active->id]);
});

it('stores a bot message without a member behind it', function () {
    $message = Message::factory()->fromBot()->create();

    expect($message->user_id)->toBeNull()
        ->and($message->author)->toBeNull()
        ->and($message->bot_name)->not->toBeNull()
        ->and($message->isFromBot())->toBeTrue()
        ->and($message->webhook)->toBeInstanceOf(Webhook::class);
});

it('keeps a message from a member marked as human', function () {
    $message = Message::factory()->create();

    expect($message->isFromBot())->toBeFalse()
        ->and($message->author)->toBeInstanceOf(User::class);
});

/**
 * The bot name is a snapshot. Deleting the webhook drops the provenance but
 * leaves the message readable, and it stays a bot message — which is exactly
 * why isFromBot() asks bot_name rather than webhook_id.
 */
it('keeps a bot message intact after its webhook is deleted', function () {
    $webhook = Webhook::factory()->create();
    $message = Message::factory()->fromBot($webhook)->create();

    $webhook->delete();
    $message->refresh();

    expect($message->webhook_id)->toBeNull()
        ->and($message->bot_name)->toBe($webhook->bot_name)
        ->and($message->isFromBot())->toBeTrue();
});

it('refuses a message that has both a member and a bot name', function () {
    Message::factory()->create(['bot_name' => 'dubbelop']);
})->throws(QueryException::class);

it('refuses a message that has neither a member nor a bot name', function () {
    Message::factory()->create(['user_id' => null]);
})->throws(QueryException::class);
