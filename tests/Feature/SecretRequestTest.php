<?php

use App\Enums\SystemRole;
use App\Features\SecretRequests;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\SecretValue;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/** @return array<string, mixed> */
function secretPayload(array $overrides = []): array
{
    return [
        'title' => 'Omgevingsvariabelen staging',
        'keys' => ['DB_PASSWORD', 'MAIL_USERNAME'],
        'valid_for_days' => 7,
        ...$overrides,
    ];
}

it('asks for a set of values and says so in the channel', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload())
        ->assertRedirect();

    $request = SecretRequest::sole();

    expect($request)
        ->workspace_id->toBe($workspace->id)
        ->channel_id->toBe($channel->id)
        ->created_by->toBe($user->id)
        ->title->toBe('Omgevingsvariabelen staging');

    expect($request->keys->pluck('name')->all())
        ->toBe(['DB_PASSWORD', 'MAIL_USERNAME']);

    // The link lands as an ordinary message, exactly as a transfer does.
    expect(Message::sole()->body)->toContain(route('secrets.show', $request->id));
});

it('does not exist at all in a workspace that never switched it on', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload())
        ->assertNotFound();

    expect(SecretRequest::count())->toBe(0);
});

/** A guest is usually the one being asked, not the one asking. */
it('does not let a guest ask for secrets', function () {
    [, $workspace] = requesterInChannel();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload())
        ->assertForbidden();
});

it('refuses a request that asks for nothing', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload(['keys' => []]))
        ->assertSessionHasErrors('keys');
});

/**
 * The name is shown back to whoever fills the form, so a "name" that is a
 * sentence of instructions would be a way to put words in the requester's mouth.
 */
it('refuses a key name that is not one', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload([
            'keys' => ['DB_PASSWORD', 'stuur je wachtwoord ook even per mail'],
        ]))
        ->assertSessionHasErrors('keys.1');
});

it('insists on a date the request stops taking answers', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), [
            'title' => 'Zonder einde',
            'keys' => ['DB_PASSWORD'],
        ])
        ->assertSessionHasErrors('valid_for_days');
});

/** Somebody pasting a list twice is not an error worth an exception. */
it('asks for a repeated key only once', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $channel]), secretPayload([
            'keys' => ['DB_PASSWORD', 'DB_PASSWORD'],
        ]))
        ->assertRedirect();

    expect(SecretRequest::sole()->keys)->toHaveCount(1);
});

it('does not let somebody ask in a channel they may not post in', function () {
    [$user, $workspace] = requesterInChannel();

    $closed = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->post(route('chat.secrets.store', [$workspace, $closed]), secretPayload())
        ->assertForbidden();

    expect(SecretRequest::count())->toBe(0);
});

it('withdraws a request without losing what was already given', function () {
    [$user, $workspace, $channel] = requesterInChannel();

    $request = SecretRequest::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
    ]);
    $key = SecretRequestKey::factory()->create(['secret_request_id' => $request->id]);
    SecretValue::record($key, 'al gegeven', null);

    actingAs($user)
        ->delete(route('chat.secrets.destroy', [$workspace, $request]))
        ->assertRedirect();

    expect($request->refresh()->isRevoked())->toBeTrue()
        // Given in good faith; throwing it away would mean asking again.
        ->and(SecretValue::count())->toBe(1);
});

/**
 * Not the channel's manager and not a workspace admin. An ability granted "just
 * for tidying up" is how the read right gets argued for later.
 */
it('does not let an admin withdraw somebody else request', function () {
    [$requester, $workspace, $channel] = requesterInChannel();

    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    $request = SecretRequest::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $requester->id,
    ]);

    actingAs($admin)
        ->delete(route('chat.secrets.destroy', [$workspace, $request]))
        ->assertForbidden();

    expect($request->refresh()->isRevoked())->toBeFalse();
});

/**
 * The composer only draws the button where both are true. Without this the
 * whole feature is unreachable from the interface, which is how it shipped the
 * first time.
 */
it('offers the message field nothing where asking is not allowed', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.secrets', false));

    Feature::for($workspace)->activate(SecretRequests::class);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.secrets', true));
});

it('offers a guest nothing, even where the workspace has it on', function () {
    [, $workspace] = requesterInChannel();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.secrets', false));
});
