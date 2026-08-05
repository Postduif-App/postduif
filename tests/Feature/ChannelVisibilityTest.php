<?php

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('makes a public channel private and hides it from everyone outside it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    // Visible to the whole workspace while it is public.
    expect(Channel::visibleTo($outsider)->whereKey($channel->id)->exists())->toBeTrue();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'private',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect($channel->fresh()->type)->toBe(ChannelType::Private)
        ->and(Channel::visibleTo($outsider)->whereKey($channel->id)->exists())->toBeFalse();
});

it('keeps the members of a channel that turns private', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'private',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect(Channel::visibleTo($member)->whereKey($channel->id)->exists())->toBeTrue();
});

it('puts a workspace admin in the channel they just closed', function () {
    [, , $workspace, $channel] = settingsFixture();

    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    expect($channel->members()->whereKey($admin->id)->exists())->toBeFalse();

    actingAs($admin)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'private',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    // Without this they would have locked themselves out with the same click.
    expect($channel->members()->whereKey($admin->id)->exists())->toBeTrue()
        ->and(Channel::visibleTo($admin)->whereKey($channel->id)->exists())->toBeTrue();
});

it('opens a private channel back up to the workspace', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['type' => ChannelType::Private]);

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'public',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect(Channel::visibleTo($outsider)->whereKey($channel->id)->exists())->toBeTrue();
});

it('refuses to turn a channel into a direct message', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'dm',
        'posting_policy' => 'everyone',
    ])->assertSessionHasErrors('type');

    expect($channel->fresh()->type)->not->toBe(ChannelType::Direct);
});

it('leaves the visibility alone when a request says nothing about it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['type' => ChannelType::Private]);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
    ])->assertRedirect();

    expect($channel->fresh()->type)->toBe(ChannelType::Private);
});

it('refuses a visibility change by a member who does not manage the channel', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    actingAs($member)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'type' => 'private',
        'posting_policy' => 'everyone',
    ])->assertForbidden();

    expect($channel->fresh()->type)->toBe(ChannelType::Public);
});
