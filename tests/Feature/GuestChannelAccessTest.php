<?php

use App\Actions\Chat\SendMessage;
use App\Enums\BroadcastMentionPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;

/**
 * A guest, the one channel they were invited to, and a public channel in the
 * same workspace that they were not.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel}
 */
function guestWithOneChannel(): array
{
    $guest = User::factory()->create();
    $workspace = workspaceWithMember($guest, SystemRole::Guest);

    $invited = channelWithMember($workspace, $guest);
    $invited->update(['name' => 'klantproject', 'slug' => 'klantproject']);

    $openToEveryone = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
        'name' => 'algemeen',
        'slug' => 'algemeen',
    ]);

    return [$guest, $workspace, $invited, $openToEveryone];
}

it('keeps public channels the guest was not invited to out of the sidebar', function () {
    [$guest, $workspace, $invited, $openToEveryone] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page
            ->where('channels.0.id', $invited->id)
            ->count('channels', 1));

    expect($workspace->channels()->visibleTo($guest)->pluck('id')->all())
        ->toBe([$invited->id])
        ->not->toContain($openToEveryone->id);
});

it('refuses a guest the public channel they were not invited to', function () {
    [$guest, $workspace, , $openToEveryone] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $openToEveryone]))
        ->assertForbidden();
});

it('refuses a guest joining a public channel on their own', function () {
    [$guest, $workspace, , $openToEveryone] = guestWithOneChannel();

    actingAs($guest)
        ->post(route('chat.channels.join', [$workspace, $openToEveryone]))
        ->assertForbidden();

    expect($openToEveryone->members()->whereKey($guest->id)->exists())->toBeFalse();
});

it('leaves the channels the guest was invited to fully readable', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertOk();
});

it('keeps messages from unreachable public channels out of a guest search', function () {
    [$guest, $workspace, $invited, $openToEveryone] = guestWithOneChannel();

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $openToEveryone->id,
        'body' => 'kwartaalcijfers',
    ]);
    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $invited->id,
        'body' => 'kwartaalcijfers voor de klant',
    ]);

    $response = actingAs($guest)
        ->getJson(route('chat.search', [$workspace, 'q' => 'kwartaalcijfers']))
        ->assertOk();

    expect(collect($response->json('results'))->pluck('channel.id')->unique()->all())
        ->toBe([$invited->id]);
});

it('keeps the member list of their own channel shut for a guest', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.canViewMembers', false)
            ->where('channel.canAddMembers', false));

    actingAs($guest)
        ->getJson(route('chat.channels.members.index', [$workspace, $invited]))
        ->assertForbidden();
});

it('hands a guest only the names from their own channel for the mention picker', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    $roommate = User::factory()->create(['name' => 'Wel in het kanaal']);
    $stranger = User::factory()->create(['name' => 'Niet in het kanaal']);

    foreach ([$roommate, $stranger] as $colleague) {
        $workspace->members()->attach($colleague->id, [
            'role' => SystemRole::Member->value,
            'joined_at' => now(),
        ]);
    }

    $invited->members()->attach($roommate->id, ['joined_at' => now()]);

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.members', fn ($members) => collect($members)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([$guest->id, $roommate->id])->sort()->values()->all()));
});

/**
 * The badge is not a permission — every one of those has its own flag — but the
 * interface has to be able to say "this person is from outside" without
 * guessing it from a handful of falses.
 */
it('marks guests as guests in the payloads their name appears in', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $invited->members()->attach($colleague->id, ['joined_at' => now()]);

    app(SendMessage::class)->handle($invited, $guest, 'Van de gast');
    app(SendMessage::class)->handle($invited, $colleague, 'Van een collega');

    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page
            ->where('auth.workspaceIsExternal', false)
            ->where('messages.0.author.isGuest', true)
            ->where('messages.1.author.isGuest', false)
            ->where('channel.members', fn ($members) => collect($members)
                ->firstWhere('id', $guest->id)['isGuest'] === true
                && collect($members)->firstWhere('id', $colleague->id)['isGuest'] === false));
});

it('tells a guest what they are', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page->where('auth.workspaceIsExternal', true));
});

it('marks a guest in the candidate list of a channel', function () {
    [$guest, $workspace] = guestWithOneChannel();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    $channel = channelWithMember($workspace, $admin);

    $candidates = actingAs($admin)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]))
        ->assertOk()
        ->json('candidates');

    expect(collect($candidates)->firstWhere('id', $guest->id)['isGuest'])->toBeTrue();
});

it('refuses a guest the workspace settings screen', function () {
    [$guest] = guestWithOneChannel();

    actingAs($guest)->get(route('workspace.edit'))->assertForbidden();

    actingAs($guest)
        ->patch(route('workspace.update'), [
            'name' => 'Overgenomen',
            'broadcast_mentions' => 'admins',
        ])
        ->assertForbidden();
});

it('refuses a guest adding somebody to a channel they are in', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($guest)
        ->post(route('chat.channels.members.store', [$workspace, $invited]), [
            'user_ids' => [$colleague->id],
        ])
        ->assertForbidden();

    expect($invited->members()->whereKey($colleague->id)->exists())->toBeFalse();
});

it('still lets a guest leave a channel', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    actingAs($guest)
        ->delete(route('chat.channels.members.destroy', [$workspace, $invited]))
        ->assertRedirect();

    expect($invited->members()->whereKey($guest->id)->exists())->toBeFalse();
});

it('refuses a guest creating a channel', function () {
    [$guest, $workspace] = guestWithOneChannel();

    actingAs($guest)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'eigen-kanaal',
            'type' => ChannelType::Public->value,
        ])
        ->assertForbidden();

    assertDatabaseMissing('channels', ['slug' => 'eigen-kanaal']);
});

it('hides the add-channel button from a guest', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateChannel', false));
});

/**
 * The other half of the isolation: everything a guest is shut out of is only
 * defensible if the channels they were invited to work normally.
 */
it('lets a guest post, answer and react in the channel they were invited to', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    // The id is the client's to pick — see StoreMessageRequest, which is what
    // lets the composer render a message before the server has seen it.
    $rootId = strtolower((string) Str::ulid());

    actingAs($guest)
        ->post(route('chat.messages.store', [$workspace, $invited]), [
            'id' => $rootId,
            'body' => 'Bij dezen de laatste versie.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $posted = Message::findOrFail($rootId);

    actingAs($guest)
        ->post(route('chat.messages.store', [$workspace, $invited]), [
            'id' => strtolower((string) Str::ulid()),
            'body' => 'En een aanvulling in de thread.',
            'parent_id' => $posted->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    actingAs($guest)
        ->post(route('chat.messages.reactions.store', [$workspace, $invited, $posted]), [
            'emoji' => '👍',
        ])
        ->assertRedirect();

    expect($posted->replies()->count())->toBe(1)
        ->and($posted->reactions()->count())->toBe(1);
});

it('refuses a guest posting in a public channel they were not invited to', function () {
    [$guest, $workspace, , $openToEveryone] = guestWithOneChannel();

    actingAs($guest)
        ->post(route('chat.messages.store', [$workspace, $openToEveryone]), [
            'id' => strtolower((string) Str::ulid()),
            'body' => 'Hallo allemaal',
        ])
        ->assertForbidden();

    expect(Message::where('channel_id', $openToEveryone->id)->count())->toBe(0);
});

/**
 * A guest's @everyone is not a workspace-wide broadcast even when the workspace
 * lets everybody use one: RecordMentions resolves it against the channel's
 * members, and the guest's channel is all they have.
 */
it('keeps a broadcast mention by a guest inside their own channel', function () {
    [$guest, $workspace, $invited, $openToEveryone] = guestWithOneChannel();

    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Everyone]);

    $roommate = User::factory()->create(['username' => 'roommate']);
    $stranger = User::factory()->create(['username' => 'stranger']);

    foreach ([$roommate, $stranger] as $colleague) {
        $workspace->members()->attach($colleague->id, [
            'role' => SystemRole::Member->value,
            'joined_at' => now(),
        ]);
    }

    $invited->members()->attach($roommate->id, ['joined_at' => now()]);
    $openToEveryone->members()->attach($stranger->id, ['joined_at' => now()]);

    $message = app(SendMessage::class)->handle($invited, $guest, 'Even iedereen: @everyone');

    expect(InboxItem::where('message_id', $message->id)->pluck('user_id')->all())
        ->toBe([$roommate->id]);
});

it('cannot address somebody outside the channel by name', function () {
    [$guest, $workspace, $invited] = guestWithOneChannel();

    $stranger = User::factory()->create(['username' => 'stranger']);
    $workspace->members()->attach($stranger->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $message = app(SendMessage::class)->handle($invited, $guest, 'Vraagje @stranger');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0);
});

it('still lets a regular member see, join and create public channels', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member, SystemRole::Member);
    $home = channelWithMember($workspace, $member);

    $openToEveryone = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
        'name' => 'algemeen',
        'slug' => 'algemeen',
    ]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $openToEveryone]))
        ->assertOk();

    actingAs($member)
        ->post(route('chat.channels.join', [$workspace, $openToEveryone]))
        ->assertRedirect();

    expect($openToEveryone->members()->whereKey($member->id)->exists())->toBeTrue()
        ->and($workspace->channels()->visibleTo($member)->pluck('id')->all())
        ->toContain($home->id, $openToEveryone->id);

    actingAs($member)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'nieuw-kanaal',
            'type' => ChannelType::Public->value,
        ])
        ->assertRedirect();
});
