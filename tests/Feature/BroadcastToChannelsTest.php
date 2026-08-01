<?php

use App\Actions\Chat\SyncChannelTags;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('places the same message in every chosen channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $second = channelWithMember($workspace, $creator);

    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Morgen is het kantoor dicht',
        'channels' => [$channel->id, $second->id],
    ])->assertRedirect();

    // A message of its own per channel, so a reply stays where it belongs.
    expect(Message::count())->toBe(2)
        ->and($channel->messages()->sole()->body)->toBe('Morgen is het kantoor dicht')
        ->and($second->messages()->sole()->body)->toBe('Morgen is het kantoor dicht');
});

it('reaches every channel carrying a chosen tag', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $second = channelWithMember($workspace, $creator);
    $untagged = channelWithMember($workspace, $creator);

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($second, ['Klant']);

    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Voor alle klanten',
        'tags' => ['Klant'],
    ])->assertRedirect();

    expect(Message::count())->toBe(2)
        ->and($untagged->messages()->count())->toBe(0);
});

it('counts a channel picked both by hand and by tag only once', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);

    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Eenmaal',
        'channels' => [$channel->id],
        'tags' => ['Klant'],
    ])->assertRedirect();

    expect($channel->messages()->count())->toBe(1);
});

it('skips a tagged channel this member may not post in', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    $locked = channelWithMember($workspace, $member);
    $locked->members()->attach($creator->id, ['joined_at' => now()]);
    $locked->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($locked, ['Klant']);

    // A tag expands to whatever carries it, which may include a channel they
    // only read along in.
    actingAs($member)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Voor iedereen',
        'tags' => ['Klant'],
    ])->assertRedirect();

    expect($channel->messages()->count())->toBe(1)
        ->and($locked->messages()->count())->toBe(0);
});

it('never reaches a channel this member cannot see', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Overal heen',
        'channels' => [$channel->id, $private->id],
    ])->assertRedirect();

    expect($private->messages()->count())->toBe(0)
        ->and($channel->messages()->count())->toBe(1);
});

it('says so when none of the choices can be posted in', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    actingAs($member)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Nergens heen',
        'channels' => [$channel->id],
    ])->assertSessionHasErrors('channels');

    expect(Message::count())->toBe(0);
});

it('insists on at least one channel or tag', function () {
    [$creator, , $workspace] = settingsFixture();

    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Naar niemand',
    ])->assertSessionHasErrors(['channels', 'tags']);

    expect(Message::count())->toBe(0);
});

it('refuses somebody outside the workspace', function () {
    [, , $workspace, $channel] = settingsFixture();
    $outsider = User::factory()->create();

    actingAs($outsider)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Van buiten',
        'channels' => [$channel->id],
    ])->assertForbidden();

    expect(Message::count())->toBe(0);
});

it('lands the sender in a channel the message reached', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    // Seeing it arrive beats being told it did.
    actingAs($creator)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Kijk maar',
        'channels' => [$channel->id],
    ])->assertRedirect(route('chat.show', [$workspace, $channel]));
});

it('is not offered to a guest, and not allowed either', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canBroadcastToChannels', false));

    // Hiding the button is not the rule; this is.
    actingAs($guest)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Naar alles',
        'channels' => [$channel->id],
    ])->assertForbidden();

    expect(Message::count())->toBe(0);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canBroadcastToChannels', true));
});

it('offers the same entry on the workspace ticket page', function () {
    [$member, , $workspace] = ticketFixture();

    // Both pages draw the same sidebar, so the entry has to be on both.
    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->where('workspace.canBroadcastToChannels', true));
});
