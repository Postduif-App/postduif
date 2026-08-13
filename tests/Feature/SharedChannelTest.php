<?php

use App\Actions\SharedChannels\AddSharedChannelMembers;
use App\Actions\SharedChannels\RevokeChannelShare;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('offers a channel to another workspace without granting anything yet', function () {
    [$host, $hostWorkspace, $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    actingAs($host)->postJson(route('chat.channels.shares.store', [$hostWorkspace, $channel]), [
        'workspace' => $guestWorkspace->slug,
    ])->assertCreated();

    $share = ChannelShare::query()->sole();

    expect($share->isPending())->toBeTrue()
        ->and($share->isLive())->toBeFalse()
        // The invitation on its own is worth nothing to the other side: not a
        // row in the sidebar, not an open door.
        ->and(Channel::reachableFrom($guestWorkspace)->whereKey($channel->id)->exists())->toBeFalse()
        ->and($guest->can('view', $channel))->toBeFalse();
});

it('still shows nobody the channel once the invitation is accepted', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    $share = ChannelShare::factory()->pending()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    actingAs($guest)->patch(route('chat.shares.update', [$guestWorkspace, $share]), [
        'accepted' => true,
    ])->assertRedirect();

    expect($share->fresh()->isLive())->toBeTrue()
        // The channel now belongs on their screen...
        ->and(Channel::reachableFrom($guestWorkspace)->whereKey($channel->id)->exists())->toBeTrue()
        // ...but membership is still what decides, for them as for anybody.
        ->and($guest->can('view', $channel))->toBeFalse();
});

it('lets the invited workspace put its own people in, and them talk', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    $colleague = User::factory()->create();
    joinWorkspace($guestWorkspace, $colleague);

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    actingAs($guest)->post(route('chat.shares.members.store', [$guestWorkspace, $share]), [
        'members' => [$colleague->id],
    ])->assertRedirect();

    expect($colleague->can('view', $channel))->toBeTrue()
        ->and($colleague->can('post', $channel))->toBeTrue();

    actingAs($colleague)->post(route('chat.messages.store', [$guestWorkspace, $channel]), [
        'id' => (string) Str::ulid(),
        'body' => 'Wij kijken ernaar',
    ])->assertRedirect();

    expect($channel->messages()->where('user_id', $colleague->id)->exists())->toBeTrue();
});

it('opens the channel under the invited workspace slug, not the host one', function () {
    [, $hostWorkspace, $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)->get(route('chat.show', [$guestWorkspace, $channel]))->assertOk();

    // The host's workspace is not theirs to stand in, share or no share.
    actingAs($guest)->get(route('chat.show', [$hostWorkspace, $channel]))->assertForbidden();
});

it('keeps a read-only share readable and silent', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    ChannelShare::factory()->readOnly()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    expect($guest->can('view', $channel))->toBeTrue()
        ->and($guest->can('post', $channel))->toBeFalse()
        // Reacting is writing too. "May read, may not write" would be a strange
        // promise if a thumbs-up still got through.
        ->and($guest->can('react', $channel))->toBeFalse();
});

it('does not let a public channel become public to the other workspace', function () {
    [, , $channel, , $guestWorkspace] = sharedChannelFixture();

    $bystander = User::factory()->create();
    joinWorkspace($guestWorkspace, $bystander);

    ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    expect($channel->type)->toBe(ChannelType::Public)
        // Public means public inside the workspace that owns it. A share is not
        // a merger of the two member lists.
        ->and($bystander->can('view', $channel))->toBeFalse()
        ->and(Channel::visibleTo($bystander)->whereKey($channel->id)->exists())->toBeFalse();
});

it('refuses an outsider the host member directory', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    // Who is in this room: yes, or you cannot tell who you are talking to.
    expect($guest->can('viewMembers', $channel))->toBeTrue()
        // A search box over the host's staff: no.
        ->and($guest->can('addMembers', $channel))->toBeFalse()
        ->and($guest->can('manageSettings', $channel))->toBeFalse()
        ->and($guest->can('archiveChannel', $channel))->toBeFalse();
});

it('takes the outside members out again when the share is revoked', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)->deleteJson(route('chat.shares.destroy', [$guestWorkspace, $share]))
        ->assertOk();

    expect($share->fresh()->isLive())->toBeFalse()
        ->and($channel->members()->whereKey($guest->id)->exists())->toBeFalse()
        ->and($guest->fresh()->can('view', $channel))->toBeFalse();
});

it('leaves somebody who belongs to both workspaces in the channel', function () {
    [, $hostWorkspace, $channel, , $guestWorkspace] = sharedChannelFixture();

    // A contractor, an owner with two teams: for them the share was never what
    // granted access, and revoking it must not take away what membership gave.
    $both = User::factory()->create();
    joinWorkspace($hostWorkspace, $both);
    joinWorkspace($guestWorkspace, $both);
    $channel->members()->attach($both->id, ['joined_at' => now()]);

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    app(RevokeChannelShare::class)->handle($share);

    expect($channel->members()->whereKey($both->id)->exists())->toBeTrue()
        ->and($both->can('view', $channel))->toBeTrue();
});

it('refuses to share a channel with the workspace that owns it', function () {
    [$host, $hostWorkspace, $channel] = sharedChannelFixture();

    actingAs($host)->postJson(route('chat.channels.shares.store', [$hostWorkspace, $channel]), [
        'workspace' => $hostWorkspace->slug,
    ])->assertStatus(422);

    expect(ChannelShare::query()->count())->toBe(0);
});

it('refuses a workspace that has shared channels switched off', function () {
    [$host, $hostWorkspace, $channel] = sharedChannelFixture();

    $closed = Workspace::factory()->create();

    actingAs($host)->postJson(route('chat.channels.shares.store', [$hostWorkspace, $channel]), [
        'workspace' => $closed->slug,
    ])->assertStatus(422);

    expect(ChannelShare::query()->count())->toBe(0);
});

it('asks the other workspace again when the terms change', function () {
    [$host, $hostWorkspace, $channel, , $guestWorkspace] = sharedChannelFixture();

    $share = ChannelShare::factory()->readOnly()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    actingAs($host)->postJson(route('chat.channels.shares.store', [$hostWorkspace, $channel]), [
        'workspace' => $guestWorkspace->slug,
        'can_post' => true,
    ])->assertCreated();

    // Widening what the other side may do is a new offer, not an edit to a
    // standing one — otherwise a host could turn "may read" into "may post"
    // without them ever being asked.
    expect($share->fresh()->isPending())->toBeTrue()
        ->and($share->fresh()->can_post)->toBeTrue();
});

it('will not add somebody who is not in the invited workspace', function () {
    [, , $channel, , $guestWorkspace] = sharedChannelFixture();

    $stranger = User::factory()->create();

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    $added = app(AddSharedChannelMembers::class)->handle($share, [$stranger->id]);

    expect($added)->toHaveCount(0)
        ->and($channel->members()->whereKey($stranger->id)->exists())->toBeFalse();
});

it('shows the host which workspaces the channel stands open to', function () {
    [$host, $hostWorkspace, $channel, , $guestWorkspace] = sharedChannelFixture();

    ChannelShare::factory()->readOnly()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    actingAs($host)->getJson(route('chat.channels.shares.index', [$hostWorkspace, $channel]))
        ->assertOk()
        ->assertJsonPath('shares.0.workspace.slug', $guestWorkspace->slug)
        ->assertJsonPath('shares.0.state', 'accepted')
        ->assertJsonPath('shares.0.canPost', false);
});

it('offers the invited workspace only its own people to add', function () {
    [, , $channel, $guest, $guestWorkspace] = sharedChannelFixture();

    $colleague = User::factory()->create();
    joinWorkspace($guestWorkspace, $colleague);

    // Somebody from the host's side, who must not turn up in this picker: it is
    // drawn from the invited workspace's directory and nothing else.
    $stranger = User::factory()->create();
    joinWorkspace($channel->workspace, $stranger);

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    $response = actingAs($guest)
        ->getJson(route('chat.shares.members.index', [$guestWorkspace, $share]))
        ->assertOk();

    $ids = array_column($response->json('candidates'), 'id');

    expect($ids)->toContain($colleague->id)
        ->toContain($guest->id)
        ->not->toContain($stranger->id);

    // The one already in the channel is listed and marked, rather than dropped
    // — a picker that hid them would read as "this colleague is not with us".
    $alreadyIn = collect($response->json('candidates'))->firstWhere('id', $guest->id);

    expect($alreadyIn['alreadyIn'])->toBeTrue();
});

it('refuses an ordinary member of the invited workspace the answer', function () {
    [, , $channel, , $guestWorkspace] = sharedChannelFixture();

    $member = User::factory()->create();
    joinWorkspace($guestWorkspace, $member);

    $share = ChannelShare::factory()->pending()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    actingAs($member)->patch(route('chat.shares.update', [$guestWorkspace, $share]), [
        'accepted' => true,
    ])->assertForbidden();

    expect($share->fresh()->isPending())->toBeTrue();
});
