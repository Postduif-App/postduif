<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

it('offers every workspace this member belongs to', function () {
    $user = User::factory()->create();
    $first = workspaceWithMember($user);
    $channel = channelWithMember($first, $user);

    $second = workspaceWithMember($user);
    $second->forceFill(['name' => 'Tweede team'])->save();

    actingAs($user)
        ->get(route('chat.show', [$first, $channel]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces', 2)
            ->where('workspaces.0.isCurrent', true)
            ->where('workspaces.1.name', 'Tweede team')
            ->where('workspaces.1.isCurrent', false));
});

it('marks the one being read rather than leaving it out', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    // The menu does the hiding, and it needs to be told which one to hide. A
    // list that already left it out could not also say "you are here".
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', $workspace->slug)
            ->where('workspaces.0.isCurrent', true));
});

it('keeps somebody else workspaces out of the list', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    workspaceWithMember(User::factory()->create());

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('workspaces', 1));
});

it('lands the switch in the other workspace most recent channel', function () {
    $user = User::factory()->create();
    $here = workspaceWithMember($user);
    $channel = channelWithMember($here, $user);

    $there = workspaceWithMember($user);
    $overThere = channelWithMember($there, $user);

    // What the menu row points at: the workspace, not a channel. Where inside
    // it somebody lands is chat.index's job, and it already knows how to pick.
    actingAs($user)
        ->get(route('chat.show', [$here, $channel]))
        ->assertOk();

    actingAs($user)
        ->get(route('chat.index', $there))
        ->assertRedirect(route('chat.show', [$there, $overThere]));
});
