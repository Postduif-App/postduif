<?php

use App\Enums\ChannelType;
use App\Models\Channel;

use function Pest\Laravel\actingAs;

it('renames a channel and moves its slug along', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'name' => 'Nieuwe Klanten',
        'topic' => 'Alles rond binnenkomende aanvragen',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    $channel->refresh();

    expect($channel->name)->toBe('nieuwe-klanten')
        ->and($channel->slug)->toBe('nieuwe-klanten')
        ->and($channel->topic)->toBe('Alles rond binnenkomende aanvragen');
});

it('refuses a name another channel in the workspace already uses', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'support',
        'slug' => 'support',
    ]);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'name' => 'Support',
        'posting_policy' => 'everyone',
    ])->assertSessionHasErrors('name');

    expect($channel->fresh()->name)->not->toBe('support');
});

it('lets a channel keep its own name while other settings change', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'name' => $channel->name,
        'posting_policy' => 'admins',
    ])->assertRedirect()->assertSessionHasNoErrors();
});

it('clears the topic when it is emptied rather than storing a blank one', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['topic' => 'Iets ouds']);

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'topic' => '   ',
        'posting_policy' => 'everyone',
    ])->assertRedirect();

    expect($channel->fresh()->topic)->toBeNull();
});

it('leaves the name alone when a request says nothing about it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $before = $channel->name;

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
    ])->assertRedirect();

    expect($channel->fresh()->name)->toBe($before);
});

it('refuses a rename by a member who does not manage the channel', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    actingAs($member)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'name' => 'van-mij-nu',
        'posting_policy' => 'everyone',
    ])->assertForbidden();

    expect($channel->fresh()->name)->not->toBe('van-mij-nu');
});

it('has no name to change on a direct message', function () {
    [$creator, , $workspace] = settingsFixture();

    $dm = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Direct,
        'name' => null,
        'slug' => null,
        'created_by' => $creator->id,
    ]);
    $dm->members()->attach($creator->id, ['joined_at' => now()]);

    // manageSettings is false for a DM, so this never gets as far as the
    // prohibited rule — which is the point: the dialog does not open either.
    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $dm]), [
        'name' => 'toch-een-naam',
        'posting_policy' => 'everyone',
    ])->assertForbidden();
});
