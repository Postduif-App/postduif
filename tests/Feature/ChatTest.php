<?php

use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

function workspaceWithMember(User $user, WorkspaceRole $role = WorkspaceRole::Member): Workspace
{
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

    return $workspace;
}

function channelWithMember(Workspace $workspace, User $user): Channel
{
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($user->id, ['joined_at' => now()]);

    return $channel;
}

it('sends the landing page to the members workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get('/app')
        ->assertRedirect(route('chat.index', $workspace, absolute: false));

    actingAs($user)
        ->get(route('chat.index', $workspace))
        ->assertRedirect(route('chat.show', [$workspace, $channel], absolute: false));
});

it('sends a guest to the login page instead of the app', function () {
    $this->get('/app')->assertRedirect(route('login'));
});

/**
 * The workspace slug is a wildcard directly under /app, so "settings" must
 * never be swallowed by it — otherwise the settings screens 404 the moment
 * someone creates a workspace, or worse, become unreachable for everyone.
 */
it('keeps the settings routes out of the workspace wildcard', function () {
    $user = User::factory()->create();
    workspaceWithMember($user);

    actingAs($user)
        ->get('/app/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/profile'));
});

it('refuses a workspace that tries to claim the settings slug', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['slug' => 'settings']);
    $workspace->members()->attach($user->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);
    channelWithMember($workspace, $user);

    // The route pattern excludes it, so this never reaches ChatController.
    actingAs($user)->get('/app/settings')->assertRedirect('/app/settings/profile');
});

it('shows a channel to a workspace member', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Hallo wereld',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/show')
            ->where('channel.id', $channel->id)
            ->has('messages', 1)
            ->where('messages.0.body', 'Hallo wereld')
        );
});

it('blocks a user who does not belong to the workspace', function () {
    $outsider = User::factory()->create();
    $workspace = workspaceWithMember(User::factory()->create());
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($outsider)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertForbidden();
});

it('hides a private channel from a workspace member who is not in it', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertForbidden();
});

it('stores a message with the client supplied ulid', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $id = (string) Str::ulid();

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), [
            'id' => $id,
            'body' => 'Eerste bericht',
        ])
        ->assertRedirect();

    $message = Message::findOrFail($id);

    expect($message->body)->toBe('Eerste bericht')
        ->and($message->workspace_id)->toBe($workspace->id)
        ->and($channel->fresh()->last_message_at)->not->toBeNull();
});

it('refuses to post in a channel the user has not joined', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), [
            'id' => (string) Str::ulid(),
            'body' => 'Mag niet',
        ])
        ->assertForbidden();

    expect(Message::count())->toBe(0);
});

it('rejects a duplicate ulid so a retried request cannot double post', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $payload = ['id' => (string) Str::ulid(), 'body' => 'Dubbel'];

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), $payload);
    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), $payload)
        ->assertSessionHasErrors('id');

    expect(Message::count())->toBe(1);
});

it('counts thread replies on the parent message', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => (string) Str::ulid(),
        'body' => 'Antwoord',
        'parent_id' => $parent->id,
    ])->assertRedirect();

    expect($parent->fresh()->reply_count)->toBe(1);
});

it('keeps thread replies out of the channel message list', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Message::factory()->inThread($parent)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('messages', 1));
});
