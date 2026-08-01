<?php

use App\Actions\Chat\SyncChannelTags;
use App\Models\Channel;
use App\Models\ChannelTag;

use function Pest\Laravel\actingAs;

it('hangs tags on a channel, creating the ones that are new', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => ['Klant', 'Urgent'],
    ])->assertRedirect();

    expect($channel->fresh()->tags->pluck('name')->all())->toBe(['Klant', 'Urgent'])
        ->and($workspace->channelTags()->count())->toBe(2);
});

it('reuses a tag that already exists elsewhere in the workspace', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $other = channelWithMember($workspace, $creator);

    app(SyncChannelTags::class)->handle($other, ['Klant']);

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => ['Klant'],
    ])->assertRedirect();

    // One tag, two channels — which is the whole point of them living on the
    // workspace rather than on a channel.
    expect($workspace->channelTags()->count())->toBe(1)
        ->and(ChannelTag::sole()->channels()->count())->toBe(2);
});

it('treats two spellings of one label as one tag', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => ['Klant', 'klant', 'KLANT'],
    ])->assertRedirect();

    expect($workspace->channelTags()->count())->toBe(1)
        ->and($channel->fresh()->tags()->count())->toBe(1);
});

it('keeps the spelling the tag was created with', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $other = channelWithMember($workspace, $creator);

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($other, ['klant']);

    expect(ChannelTag::sole()->name)->toBe('Klant');
});

it('takes a tag off a channel and leaves it on the other one', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $other = channelWithMember($workspace, $creator);

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($other, ['Klant']);

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => [],
    ])->assertRedirect();

    expect($channel->fresh()->tags()->count())->toBe(0)
        ->and($other->fresh()->tags()->count())->toBe(1)
        ->and($workspace->channelTags()->count())->toBe(1);
});

it('clears out a tag that is left on nothing', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    app(SyncChannelTags::class)->handle($channel, ['Typfout']);

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => [],
    ])->assertRedirect();

    // Not a label somebody is saving for later: tags only come into existence
    // by being attached, so an orphan is the remains of a typo.
    expect($workspace->channelTags()->count())->toBe(0);
});

it('ignores blank entries and whitespace around a name', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => ['  Klant  ', '', '   '],
    ])->assertRedirect();

    expect($channel->fresh()->tags->pluck('name')->all())->toBe(['Klant']);
});

it('refuses tagging by somebody who does not manage the channel', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    actingAs($member)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => ['Van mij'],
    ])->assertForbidden();

    expect($channel->fresh()->tags()->count())->toBe(0);
});

it('refuses more tags than a label still tells apart', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->put(route('chat.channels.tags.update', [$workspace, $channel]), [
        'tags' => array_map(fn (int $i): string => "tag-{$i}", range(1, 21)),
    ])->assertSessionHasErrors('tags');

    expect($channel->fresh()->tags()->count())->toBe(0);
});

it('keeps tags of one workspace out of another', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    [$otherCreator, , , $otherChannel] = settingsFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($otherChannel, ['Klant']);

    // Same word, two workspaces, two rows: uniqueness is per workspace.
    expect(ChannelTag::count())->toBe(2)
        ->and($workspace->channelTags()->count())->toBe(1);
});

it('sends the channel its tags and the workspace its whole list', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $other = channelWithMember($workspace, $creator);

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    app(SyncChannelTags::class)->handle($other, ['Intern']);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.tags', ['Klant'])
            // Everything in the workspace, so the picker can suggest a label
            // that exists rather than have it retyped into existence.
            ->where('workspaceTags', ['Intern', 'Klant'])
        );
});

it('lets go of its tags when the channel is deleted', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);
    $channel->delete();

    expect(Channel::whereKey($channel->id)->exists())->toBeFalse()
        ->and(ChannelTag::sole()->channels()->count())->toBe(0);
});

it('offers the sidebar only the tags on channels this member can see', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => 'private',
    ]);

    app(SyncChannelTags::class)->handle($channel, ['Zichtbaar']);
    app(SyncChannelTags::class)->handle($private, ['Verborgen']);

    // A label on a channel they cannot open would tell them which subjects
    // exist behind a door they may not go through.
    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspaceTags', ['Zichtbaar']));
});

it('hangs the tags on the sidebar rows themselves', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channels.0.tags', ['Klant']));
});

it('gives the ticket page the same tags as a channel page', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);

    // Both pages draw the same sidebar, so both need what it filters on.
    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->where('workspaceTags', ['Klant']));
});

it('keeps tags out of sight for a guest', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();

    app(SyncChannelTags::class)->handle($channel, ['Klant']);

    // A tag says how a channel is filed internally — "klant", "escalatie" —
    // which is not the customer's business, even in their own channel.
    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.tags', [])
            ->where('workspaceTags', [])
            ->where('channels.0.tags', [])
        );

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.tags', ['Klant']));
});
