<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

/**
 * Every page in the panel, rendered end to end.
 *
 * The per-resource tests drive the Livewire components directly, which skips the
 * page and layout around them. These requests catch the things that only break
 * once a real page is assembled: a missing relation in an infolist, a column
 * pointing at an attribute that does not exist, a policy method nobody added.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->workspace = Workspace::factory()->create();
    $this->channel = Channel::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->message = Message::factory()->create([
        'workspace_id' => $this->workspace->id,
        'channel_id' => $this->channel->id,
    ]);
});

test('it renders every list page', function (string $path) {
    $this->get($path)->assertSuccessful();
})->with([
    '/admin',
    '/admin/workspaces',
    '/admin/users',
    '/admin/channels',
    '/admin/messages',
]);

test('it renders the workspace pages', function () {
    $this->get("/admin/workspaces/{$this->workspace->slug}")->assertSuccessful();
    $this->get("/admin/workspaces/{$this->workspace->slug}/edit")->assertSuccessful();
    $this->get('/admin/workspaces/create')->assertSuccessful();
});

test('it renders the user pages', function () {
    $user = User::factory()->create();

    $this->get("/admin/users/{$user->getKey()}")->assertSuccessful();
    $this->get("/admin/users/{$user->getKey()}/edit")->assertSuccessful();
});

test('it renders the channel pages', function () {
    $this->get("/admin/channels/{$this->channel->getKey()}")->assertSuccessful();
    $this->get("/admin/channels/{$this->channel->getKey()}/edit")->assertSuccessful();
});

test('it renders the message page', function () {
    $this->get("/admin/messages/{$this->message->getKey()}")->assertSuccessful();
});

test('it refuses every page to someone who is not a moderator', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertForbidden();
})->with([
    '/admin',
    '/admin/workspaces',
    '/admin/users',
    '/admin/channels',
    '/admin/messages',
]);
