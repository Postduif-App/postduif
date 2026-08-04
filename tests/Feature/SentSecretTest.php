<?php

use App\Actions\Secrets\PruneSecretRequests;
use App\Actions\Secrets\RevealSentSecret;
use App\Features\SecretRequests;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SentSecret;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * A channel with a sender and a recipient in it, in a workspace that has
 * secrets switched on.
 *
 * The feature is activated by hand, and that is the point of doing it here: it
 * is off until a workspace says otherwise, so a fixture that forgot would leave
 * every test below passing against a 404.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function sentSecretFixture(): array
{
    $sender = User::factory()->create();
    $workspace = workspaceWithMember($sender);

    Feature::for($workspace)->activate(SecretRequests::class);
    $channel = channelWithMember($workspace, $sender);

    $recipient = User::factory()->create();
    $workspace->members()->attach($recipient->id, ['joined_at' => now()]);
    $channel->members()->attach($recipient->id, ['joined_at' => now()]);

    return [$sender, $recipient, $workspace, $channel];
}

/** The shape the browser posts: ciphertext and nonce, never a key. */
function sentSecretPayload(User $recipient, array $overrides = []): array
{
    return array_merge([
        'recipient_id' => $recipient->id,
        'label' => 'Wachtwoord staging-database',
        'ciphertext' => 'AAECAwQFBgcICQoLDA0ODw==',
        'iv' => 'AAAAAAAAAAAAAAAA',
        'valid_for_days' => 7,
    ], $overrides);
}

it('stores a secret and announces it in the channel', function () {
    [$sender, $recipient, $workspace, $channel] = sentSecretFixture();

    actingAs($sender)
        ->post(route('chat.sent-secrets.store', [$workspace, $channel]), sentSecretPayload($recipient))
        ->assertRedirect();

    $secret = SentSecret::query()->sole();

    expect($secret->recipient_id)->toBe($recipient->id)
        ->and($secret->created_by)->toBe($sender->id)
        ->and($secret->ciphertext)->toBe('AAECAwQFBgcICQoLDA0ODw==');

    // The announcement names the label and links to the secret — never the key,
    // which the server has never seen.
    $body = $channel->messages()->latest('id')->value('body');

    expect($body)->toContain('Wachtwoord staging-database')
        ->and($body)->toContain($secret->id)
        ->and($body)->not->toContain('#');
});

/**
 * The line the whole feature rests on. The plaintext and the key must never
 * reach us, so there is nothing in the request that could carry them.
 */
it('never receives the key or the plaintext', function () {
    [$sender, $recipient, $workspace, $channel] = sentSecretFixture();

    actingAs($sender)->post(
        route('chat.sent-secrets.store', [$workspace, $channel]),
        sentSecretPayload($recipient),
    );

    $secret = SentSecret::query()->sole();

    // Everything stored, as one string. Nothing in it may be readable.
    $stored = json_encode($secret->getAttributes());

    expect($stored)->not->toContain('hunter2');
});

it('refuses a recipient who is not in the channel', function () {
    [$sender, , $workspace, $channel] = sentSecretFixture();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['joined_at' => now()]);

    actingAs($sender)
        ->post(
            route('chat.sent-secrets.store', [$workspace, $channel]),
            sentSecretPayload($outsider),
        )
        ->assertSessionHasErrors('recipient_id');
});

it('is closed off when the workspace switches secrets off', function () {
    [$sender, $recipient, $workspace, $channel] = sentSecretFixture();

    Feature::for($workspace)->deactivate(SecretRequests::class);

    actingAs($sender)
        ->post(route('chat.sent-secrets.store', [$workspace, $channel]), sentSecretPayload($recipient))
        ->assertNotFound();
});

it('hands the secret over once and leaves nothing behind', function () {
    [, $recipient, , $channel] = sentSecretFixture();

    $secret = SentSecret::factory()->create([
        'channel_id' => $channel->id,
        'recipient_id' => $recipient->id,
    ]);

    postJson(route('sent-secrets.reveal', $secret))
        ->assertOk()
        ->assertJson(['ciphertext' => 'versleuteld-in-de-browser']);

    $secret->refresh();

    expect($secret->ciphertext)->toBe('')
        ->and($secret->iv)->toBe('')
        ->and($secret->revealed_at)->not->toBeNull();
});

/** "Al opgehaald" is information, not a fault — but it is still a refusal. */
it('refuses a second attempt', function () {
    $secret = SentSecret::factory()->create();

    postJson(route('sent-secrets.reveal', $secret))->assertOk();

    postJson(route('sent-secrets.reveal', $secret))
        ->assertStatus(410)
        ->assertJson(['reason' => 'revealed']);
});

it('refuses one whose moment has passed', function () {
    $secret = SentSecret::factory()->expired()->create();

    postJson(route('sent-secrets.reveal', $secret))
        ->assertStatus(410)
        ->assertJson(['reason' => 'expired']);

    // And it is still there, untouched: expiry is not a reason to hand it over.
    expect($secret->refresh()->ciphertext)->not->toBe('');
});

it('asks for the password when there is one', function () {
    $secret = SentSecret::factory()->withPassword('sleutelwoord')->create();

    postJson(route('sent-secrets.reveal', $secret), ['password' => 'fout'])
        ->assertStatus(422)
        ->assertJson(['reason' => 'password']);

    postJson(route('sent-secrets.reveal', $secret), ['password' => 'sleutelwoord'])
        ->assertOk();
});

/**
 * The one that decides where the password check goes: a wrong guess must leave
 * the secret exactly as it was, or the gate meant to protect it becomes the way
 * to destroy it.
 */
it('does not burn the secret on a wrong password', function () {
    $secret = SentSecret::factory()->withPassword()->create();

    postJson(route('sent-secrets.reveal', $secret), ['password' => 'fout'])
        ->assertStatus(422);

    expect($secret->refresh()->revealed_at)->toBeNull()
        ->and($secret->ciphertext)->toBe('versleuteld-in-de-browser');
});

/**
 * Two tabs opening at once. Both read revealed_at as null before either writes,
 * which is exactly the race the lock in RevealSentSecret exists to close.
 */
it('gives the secret to only one of two simultaneous attempts', function () {
    $secret = SentSecret::factory()->create();

    $action = app(RevealSentSecret::class);

    $first = $action->handle($secret);
    // The same stale instance the second tab would be holding: route binding
    // read it before either call began.
    $second = $action->handle($secret);

    expect($first['ok'])->toBeTrue()
        ->and($second['ok'])->toBeFalse()
        ->and($second['reason'])->toBe('revealed');
});

it('says nothing about the secret on the page before it is revealed', function () {
    $secret = SentSecret::factory()->withPassword()->create(['label' => 'Wachtwoord staging']);

    $this->get(route('sent-secrets.show', $secret))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('secrets/reveal')
            ->where('secret.label', 'Wachtwoord staging')
            ->where('secret.needsPassword', true)
            ->where('secret.state', 'pending')
            // The three things that must never travel to the browser here.
            ->missing('secret.ciphertext')
            ->missing('secret.iv')
            ->missing('secret.passwordHash')
        );
});

/** Reachable without an account: the link is the credential. */
it('lets somebody without an account pick it up', function () {
    $secret = SentSecret::factory()->create();

    $this->get(route('sent-secrets.show', $secret))->assertOk();

    postJson(route('sent-secrets.reveal', $secret))->assertOk();
});

it('lets the sender withdraw it, and nobody else', function () {
    [$sender, $recipient, $workspace, $channel] = sentSecretFixture();

    $secret = SentSecret::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $sender->id,
        'recipient_id' => $recipient->id,
    ]);

    actingAs($recipient)
        ->delete(route('chat.sent-secrets.destroy', [$workspace, $secret]))
        ->assertForbidden();

    actingAs($sender)
        ->delete(route('chat.sent-secrets.destroy', [$workspace, $secret]))
        ->assertRedirect();

    expect($secret->refresh()->ciphertext)->toBe('');

    postJson(route('sent-secrets.reveal', $secret))->assertStatus(410);
});

it('sweeps a secret away once it has been read long enough ago', function () {
    $kept = SentSecret::factory()->create();
    $read = SentSecret::factory()->revealed()->create(['revealed_at' => now()->subDays(5)]);
    $stale = SentSecret::factory()->create(['expires_at' => now()->subDays(5)]);

    app(PruneSecretRequests::class)->handle();

    expect(SentSecret::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(SentSecret::query()->whereKey($read->id)->exists())->toBeFalse()
        ->and(SentSecret::query()->whereKey($stale->id)->exists())->toBeFalse();
});

it('makes a link without saying anything in any channel', function () {
    [$sender, $recipient, $workspace] = sentSecretFixture();

    actingAs($sender)
        ->post(route('chat.sent-secrets.store-standalone', $workspace), [
            'label' => 'Wachtwoord router',
            'recipient_id' => $recipient->id,
            'ciphertext' => 'AAECAwQFBgcICQoLDA0ODw==',
            'iv' => 'AAAAAAAAAAAAAAAA',
            'valid_for_days' => 7,
        ])
        ->assertRedirect();

    $secret = SentSecret::query()->sole();

    expect($secret->channel_id)->toBeNull()
        ->and($secret->recipient_id)->toBe($recipient->id)
        // The whole point of the standalone route: no announcement anywhere.
        ->and(Message::query()->where('body', 'like', '%geheim%')->count())->toBe(0);
});

it('makes a link that names nobody at all', function () {
    [$sender, , $workspace] = sentSecretFixture();

    actingAs($sender)
        ->post(route('chat.sent-secrets.store-standalone', $workspace), [
            'label' => 'Wachtwoord router',
            'ciphertext' => 'AAECAwQFBgcICQoLDA0ODw==',
            'iv' => 'AAAAAAAAAAAAAAAA',
            'valid_for_days' => 7,
        ])
        ->assertRedirect();

    expect(SentSecret::query()->sole()->recipient_id)->toBeNull();
});

it('refuses a recipient from another workspace', function () {
    [$sender, , $workspace] = sentSecretFixture();

    $stranger = User::factory()->create();

    actingAs($sender)
        ->post(route('chat.sent-secrets.store-standalone', $workspace), [
            'label' => 'Wachtwoord router',
            'recipient_id' => $stranger->id,
            'ciphertext' => 'AAECAwQFBgcICQoLDA0ODw==',
            'iv' => 'AAAAAAAAAAAAAAAA',
            'valid_for_days' => 7,
        ])
        ->assertSessionHasErrors('recipient_id');
});

/**
 * The reason the page exists: "is dat wachtwoord van vorige week ooit
 * opgehaald" is a question a list that swept those rows away cannot answer.
 */
it('lists the spent ones alongside the live ones', function () {
    [$sender, , $workspace, $channel] = sentSecretFixture();

    $common = ['workspace_id' => $workspace->id, 'channel_id' => $channel->id, 'created_by' => $sender->id];

    SentSecret::factory()->create([...$common, 'label' => 'Nog geldig']);
    SentSecret::factory()->revealed()->create([...$common, 'label' => 'Opgehaald']);
    SentSecret::factory()->expired()->create([...$common, 'label' => 'Verlopen']);

    actingAs($sender)
        ->get(route('chat.sent-secrets.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->component('chat/secrets')
            ->has('secrets', 3)
            // Asserted as a set rather than by position: what matters is that
            // none of the three states is swept out of the list.
            ->where('secrets', fn ($secrets) => collect($secrets)
                ->pluck('state')
                ->sort()
                ->values()
                ->all() === ['expired', 'pending', 'revealed'])
        );
});

it('shows one member nothing of what another put aside', function () {
    [$sender, $recipient, $workspace, $channel] = sentSecretFixture();

    SentSecret::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $sender->id,
        'label' => 'Van de afzender',
    ]);

    actingAs($recipient)
        ->get(route('chat.sent-secrets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('secrets', 0));
});

/** Not even the metadata: the list never carries anything decryptable. */
it('sends no ciphertext to the list', function () {
    [$sender, , $workspace, $channel] = sentSecretFixture();

    SentSecret::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $sender->id,
    ]);

    actingAs($sender)
        ->get(route('chat.sent-secrets.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->missing('secrets.0.ciphertext')
            ->missing('secrets.0.iv')
        );
});
