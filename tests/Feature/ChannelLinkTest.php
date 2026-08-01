<?php

use App\Models\ChannelLink;

use function Pest\Laravel\actingAs;

it('adds a button to the bar and puts it at the end', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    ChannelLink::factory()->create(['channel_id' => $channel->id, 'position' => 0]);

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Planning',
        'url' => 'https://example.com/planning',
    ])->assertRedirect();

    $added = $channel->links()->where('label', 'Planning')->sole();

    expect($added->url)->toBe('https://example.com/planning')
        ->and($added->position)->toBe(1);
});

it('refuses an address that is not a web address', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    // A bar drawn for every reader is not a place to hand out script execution.
    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Stiekem',
        'url' => 'javascript:alert(1)',
    ])->assertSessionHasErrors('url');

    expect($channel->links()->count())->toBe(0);
});

it('changes the label and the address of a button', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $link = ChannelLink::factory()->create(['channel_id' => $channel->id]);

    actingAs($creator)->patch(route('chat.channels.links.update', [$workspace, $channel, $link]), [
        'label' => 'Nieuwe naam',
        'url' => 'https://example.com/anders',
    ])->assertRedirect();

    expect($link->fresh()->label)->toBe('Nieuwe naam')
        ->and($link->fresh()->url)->toBe('https://example.com/anders');
});

it('removes a button', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $link = ChannelLink::factory()->create(['channel_id' => $channel->id]);

    actingAs($creator)->delete(route('chat.channels.links.destroy', [$workspace, $channel, $link]))
        ->assertRedirect();

    expect($channel->links()->count())->toBe(0);
});

it('puts the buttons in the order it is given', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $first = ChannelLink::factory()->create(['channel_id' => $channel->id, 'position' => 0]);
    $second = ChannelLink::factory()->create(['channel_id' => $channel->id, 'position' => 1]);
    $third = ChannelLink::factory()->create(['channel_id' => $channel->id, 'position' => 2]);

    actingAs($creator)->put(route('chat.channels.links.reorder', [$workspace, $channel]), [
        'ids' => [$third->id, $first->id, $second->id],
    ])->assertRedirect();

    expect($channel->links()->pluck('id')->all())
        ->toBe([$third->id, $first->id, $second->id]);
});

it('ignores an id from another channel while reordering', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $mine = ChannelLink::factory()->create(['channel_id' => $channel->id, 'position' => 0]);

    $elsewhere = ChannelLink::factory()->create([
        'channel_id' => channelWithMember($workspace, $creator)->id,
        'position' => 7,
    ]);

    actingAs($creator)->put(route('chat.channels.links.reorder', [$workspace, $channel]), [
        'ids' => [$elsewhere->id, $mine->id],
    ])->assertRedirect();

    expect($channel->links()->pluck('id')->all())->toBe([$mine->id])
        ->and($elsewhere->fresh()->position)->toBe(7);
});

it('refuses a button from a member who does not manage the channel', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    actingAs($member)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Van mij',
        'url' => 'https://example.com',
    ])->assertForbidden();

    expect($channel->links()->count())->toBe(0);
});

it('is a 404 for a button that belongs to another channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $elsewhere = ChannelLink::factory()->create([
        'channel_id' => channelWithMember($workspace, $creator)->id,
    ]);

    actingAs($creator)
        ->delete(route('chat.channels.links.destroy', [$workspace, $channel, $elsewhere]))
        ->assertNotFound();
});

it('sends the buttons along with the channel, for guests too', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();
    ChannelLink::factory()->create(['channel_id' => $channel->id, 'label' => 'Planning']);

    foreach ([$member, $guest] as $viewer) {
        actingAs($viewer)
            ->get(route('chat.show', [$workspace, $channel]))
            ->assertInertia(fn ($page) => $page->where('channel.links.0.label', 'Planning'));
    }
});
