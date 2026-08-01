<?php

use App\Models\Message;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('refuses a reply in a channel that closed its threads', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['replies_open' => false]);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
    ]);

    actingAs($creator)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Toch even reageren',
        'parent_id' => $parent->id,
    ])->assertForbidden();

    expect($parent->replies()->count())->toBe(0);
});

it('still takes an ordinary message in that channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $channel->update(['replies_open' => false]);

    // Announcing is exactly what such a channel is for; only discussing is off.
    actingAs($creator)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Nieuwe kantoortijden vanaf maandag',
    ])->assertRedirect();

    expect($channel->messages()->count())->toBe(1);
});

it('keeps existing threads readable after answering is shut', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
    ]);
    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'parent_id' => $parent->id,
    ]);

    $channel->update(['replies_open' => false]);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel, 'thread' => $parent->id]))
        ->assertInertia(fn ($page) => $page
            ->has('thread.replies', 1)
            ->where('channel.repliesOpen', false)
            ->where('channel.canReply', false)
        );
});

it('saves the reply setting from the channel dialog', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
        'replies_open' => false,
    ])->assertRedirect();

    expect($channel->fresh()->replies_open)->toBeFalse();
});

it('leaves threads open when a request says nothing about them', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
    ])->assertRedirect();

    expect($channel->fresh()->replies_open)->toBeTrue();
});

it('keeps an admins-only channel answerable, which is the point of the split', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    $channel->update(['posting_policy' => 'admins']);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
    ]);

    actingAs($member)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Vraagje hierover',
        'parent_id' => $parent->id,
    ])->assertRedirect();

    expect($parent->replies()->count())->toBe(1);
});
