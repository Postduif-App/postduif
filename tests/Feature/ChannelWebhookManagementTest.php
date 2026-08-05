<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * An admin who runs the workspace, an ordinary member alongside them, and a
 * channel both are in.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function channelWithManagerAndMember(): array
{
    $manager = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($manager, SystemRole::Admin);
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    $channel = channelWithMember($workspace, $manager);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return [$manager, $member, $workspace, $channel];
}

it('creates a webhook and hands back its token and url', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $response = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->assertCreated();

    $token = $response->json('token');

    expect($token)->toStartWith('whk_')
        ->and($response->json('url'))->toBe(route('webhooks.messages.store', $token))
        ->and($response->json('webhook.botName'))->toBe('Buildbot')
        ->and($response->json('webhook.createdBy'))->toBe($manager->name);

    $webhook = Webhook::firstOrFail();

    expect($webhook->channel_id)->toBe($channel->id)
        ->and($webhook->workspace_id)->toBe($workspace->id)
        ->and($webhook->token_hash)->toBe(Webhook::hashToken($token));

    // The URL is deliberately available again later — that is the point of
    // storing the token. The hash never leaves the database in any form.
    actingAs($manager)
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertOk()
        ->assertDontSee($webhook->token_hash);
});

it('makes the fresh token work straight away', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $token = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->json('token');

    $this->postJson(route('webhooks.messages.store', $token), ['text' => 'De build is groen'])
        ->assertCreated();

    expect($channel->messages()->first()?->bot_name)->toBe('Buildbot');
});

it('lists the webhooks of the channel without their secrets', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $webhook = Webhook::factory()->for($channel)->create(['name' => 'CI', 'bot_name' => 'Buildbot']);
    $elsewhere = Webhook::factory()->create(['name' => 'Ergens anders']);

    $listed = actingAs($manager)
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertOk()
        ->json('webhooks');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['id'])->toBe($webhook->id)
        ->and($listed[0]['name'])->toBe('CI')
        ->and($listed[0])->not->toHaveKey('token_hash')
        ->and($listed[0])->not->toHaveKey('tokenHash');

    expect(collect($listed)->pluck('name'))->not->toContain($elsewhere->name);
});

it('revokes a webhook so its token stops working', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $token = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->json('token');

    $webhook = Webhook::firstOrFail();

    actingAs($manager)
        ->deleteJson(route('chat.channels.webhooks.destroy', [$workspace, $channel, $webhook]))
        ->assertOk();

    expect($webhook->refresh()->revoked_at)->not->toBeNull();

    $this->postJson(route('webhooks.messages.store', $token), ['text' => 'Nog steeds?'])
        ->assertNotFound();
});

it('keeps an ordinary member from managing webhooks', function () {
    [, $member, $workspace, $channel] = channelWithManagerAndMember();

    $webhook = Webhook::factory()->for($channel)->create();

    actingAs($member)
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertForbidden();

    actingAs($member)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'Stiekem',
            'bot_name' => 'Stiekem',
        ])
        ->assertForbidden();

    actingAs($member)
        ->deleteJson(route('chat.channels.webhooks.destroy', [$workspace, $channel, $webhook]))
        ->assertForbidden();

    expect(Webhook::count())->toBe(1)
        ->and($webhook->refresh()->revoked_at)->toBeNull();
});

it('keeps somebody outside the workspace out entirely', function () {
    [, , $workspace, $channel] = channelWithManagerAndMember();

    actingAs(User::factory()->create())
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertForbidden();
});

/**
 * The route is scoped, so a webhook belonging to another channel is not
 * something the controller has to think about — it never arrives.
 */
it('refuses to revoke a webhook from another channel', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $elsewhere = Webhook::factory()->create();

    actingAs($manager)
        ->deleteJson(route('chat.channels.webhooks.destroy', [$workspace, $channel, $elsewhere]))
        ->assertNotFound();

    expect($elsewhere->refresh()->revoked_at)->toBeNull();
});

it('insists on a name and a bot name', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'bot_name']);
});

it('has nothing to manage on an archived channel', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $channel->forceFill(['archived_at' => now()])->save();

    actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->assertForbidden();
});

it('hands the url of an existing webhook back on the list', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $token = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->json('token');

    // A second, unrelated request: the point is that the URL survives the one
    // response that used to be the only place it existed.
    $listed = actingAs($manager)
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertOk()
        ->json('webhooks.0');

    expect($listed['url'])->toBe(route('webhooks.messages.store', $token));
});

it('offers no url for a webhook made before tokens were kept', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    Webhook::factory()->for($channel)->create();

    $listed = actingAs($manager)
        ->getJson(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertOk()
        ->json('webhooks.0');

    expect($listed['url'])->toBeNull();
});

it('replaces the url and retires the old one', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $old = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
            'name' => 'CI',
            'bot_name' => 'Buildbot',
        ])
        ->json('token');

    $webhook = Webhook::firstOrFail();

    $fresh = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.regenerate', [$workspace, $channel, $webhook]))
        ->assertOk()
        ->json('webhook.url');

    expect($fresh)->not->toBe(route('webhooks.messages.store', $old));

    $this->postJson(route('webhooks.messages.store', $old), ['text' => 'Nog steeds?'])
        ->assertNotFound();

    $this->postJson($fresh, ['text' => 'En nu?'])->assertCreated();
});

it('brings a revoked webhook back with a new url', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $webhook = Webhook::factory()->for($channel)->revoked()->create();

    $url = actingAs($manager)
        ->postJson(route('chat.channels.webhooks.regenerate', [$workspace, $channel, $webhook]))
        ->assertOk()
        ->json('webhook.url');

    expect($webhook->refresh()->revoked_at)->toBeNull();

    $this->postJson($url, ['text' => 'Weer aan'])->assertCreated();
});

it('keeps an ordinary member from replacing a url', function () {
    [, $member, $workspace, $channel] = channelWithManagerAndMember();

    $webhook = Webhook::factory()->for($channel)->create();

    actingAs($member)
        ->postJson(route('chat.channels.webhooks.regenerate', [$workspace, $channel, $webhook]))
        ->assertForbidden();
});

it('refuses to replace the url of a webhook from another channel', function () {
    [$manager, , $workspace, $channel] = channelWithManagerAndMember();

    $elsewhere = Webhook::factory()->create();

    actingAs($manager)
        ->postJson(route('chat.channels.webhooks.regenerate', [$workspace, $channel, $elsewhere]))
        ->assertNotFound();
});

it('creates a webhook that reads its text from a path', function () {
    [$user, , $workspace, $channel] = channelWithManagerAndMember();

    actingAs($user)->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
        'name' => 'GitHub',
        'bot_name' => 'GitHub',
        'body_path' => 'issue.title',
    ])->assertCreated()
        ->assertJsonPath('webhook.bodyPath', 'issue.title');

    expect(Webhook::sole()->body_path)->toBe('issue.title');
});

it('points an existing webhook at a different part of the payload', function () {
    [$user, , $workspace, $channel] = channelWithManagerAndMember();
    $webhook = Webhook::factory()->for($channel)->create(['body_path' => null]);

    actingAs($user)->patchJson(
        route('chat.channels.webhooks.update', [$workspace, $channel, $webhook]),
        [
            'name' => $webhook->name,
            'bot_name' => $webhook->bot_name,
            'body_path' => 'payload.commits.0.message',
        ],
    )->assertOk();

    expect($webhook->refresh()->body_path)->toBe('payload.commits.0.message');
});

/** Blank means the original contract, not a path that matches nothing. */
it('reads an emptied path as no path at all', function () {
    [$user, , $workspace, $channel] = channelWithManagerAndMember();
    $webhook = Webhook::factory()->for($channel)->create(['body_path' => 'issue.title']);

    actingAs($user)->patchJson(
        route('chat.channels.webhooks.update', [$workspace, $channel, $webhook]),
        ['name' => $webhook->name, 'bot_name' => $webhook->bot_name, 'body_path' => ''],
    )->assertOk();

    expect($webhook->refresh()->body_path)->toBeNull();
});

/**
 * Caught here rather than at three in the morning, when the path it names has
 * been quietly matching nothing for a week.
 */
it('refuses a path that is not a path', function () {
    [$user, , $workspace, $channel] = channelWithManagerAndMember();

    actingAs($user)->postJson(route('chat.channels.webhooks.store', [$workspace, $channel]), [
        'name' => 'GitHub',
        'bot_name' => 'GitHub',
        'body_path' => 'issue title!',
    ])->assertJsonValidationErrors('body_path');
});

it('refuses to change a webhook by somebody who may not manage the channel', function () {
    [, , $workspace, $channel] = channelWithManagerAndMember();
    $webhook = Webhook::factory()->for($channel)->create();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($outsider)->patchJson(
        route('chat.channels.webhooks.update', [$workspace, $channel, $webhook]),
        ['name' => 'Gekaapt', 'bot_name' => 'Gekaapt', 'body_path' => null],
    )->assertForbidden();

    expect($webhook->refresh()->name)->not->toBe('Gekaapt');
});
