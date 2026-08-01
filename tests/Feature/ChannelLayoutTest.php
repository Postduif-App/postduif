<?php

use App\Enums\ChannelLayout;
use App\Enums\ChannelType;
use App\Models\Channel;

use function Pest\Laravel\actingAs;

it('creates a channel that reads as a feed', function () {
    [$creator, , $workspace] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.store', $workspace), [
        'name' => 'Nieuws',
        'type' => 'public',
        'layout' => 'feed',
    ])->assertRedirect();

    expect(Channel::where('slug', 'nieuws')->sole()->layout)->toBe(ChannelLayout::Feed);
});

it('makes an ordinary conversation when nothing is said about the layout', function () {
    [$creator, , $workspace] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.store', $workspace), [
        'name' => 'Marketing',
        'type' => 'public',
    ])->assertRedirect();

    expect(Channel::where('slug', 'marketing')->sole()->layout)->toBe(ChannelLayout::Chat);
});

it('turns an existing channel into a feed and back', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'layout' => 'feed',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect($channel->fresh()->layout)->toBe(ChannelLayout::Feed);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'layout' => 'chat',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect($channel->fresh()->layout)->toBe(ChannelLayout::Chat);
});

it('keeps a feed private when it is private', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    // The whole reason layout is its own column: company news is exactly the
    // kind of feed that belongs behind a private channel.
    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'layout' => 'feed',
        'type' => 'private',
        'posting_policy' => 'admins',
    ])->assertRedirect();

    $channel->refresh();

    expect($channel->layout)->toBe(ChannelLayout::Feed)
        ->and($channel->type)->toBe(ChannelType::Private)
        ->and($channel->isFeed())->toBeTrue();
});

it('leaves the layout alone when a request says nothing about it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['layout' => ChannelLayout::Feed]);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
    ])->assertRedirect();

    expect($channel->fresh()->layout)->toBe(ChannelLayout::Feed);
});

it('is never a feed for a direct message', function () {
    [$creator, , $workspace] = settingsFixture();

    $dm = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Direct,
        'name' => null,
        'slug' => null,
        // Even with the column set, which is what isFeed() guards against.
        'layout' => ChannelLayout::Feed,
    ]);

    expect($dm->isFeed())->toBeFalse();
});

it('sends the layout along with the channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['layout' => ChannelLayout::Feed]);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.layout', 'feed'));
});
