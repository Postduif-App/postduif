<?php

use App\Actions\Chat\SendMessage;
use App\Enums\SystemRole;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;

use function Pest\Laravel\actingAs;

it('finds a bot message in search and labels it as a bot', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    app(SendMessage::class)->fromWebhook($webhook, 'De deployment is klaar');

    $hit = actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=deployment')
        ->assertOk()
        ->json('results.0');

    expect($hit['author'])->toBe('Buildbot')
        ->and($hit['authorIsBot'])->toBeTrue()
        ->and($hit['body'])->toBe('De deployment is klaar');
});

it('marks a message from a member as not a bot in search', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    app(SendMessage::class)->handle($channel, $user, 'De deployment is klaar');

    expect(actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=deployment')
        ->json('results.0.authorIsBot'))->toBeFalse();
});

/**
 * The unread count excludes "your own messages", which is expressed as a
 * not-equal against the sender. A bot has no sender, and in SQL a comparison
 * against null is never true — so without care a webhook would notify nobody.
 */
it('counts a bot message as unread', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $other = channelWithMember($workspace, $user);

    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);
    app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    actingAs($user)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->firstWhere('id', $channel->id)['unreadCount'] === 1
            )
        );
});

it('lets whoever manages the channel delete a bot message', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);
    $channel = channelWithMember($workspace, $owner);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Oeps, per ongeluk');

    expect($owner->can('delete', $message))->toBeTrue();
});

it('keeps a plain member from deleting a bot message', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($owner, SystemRole::Admin);
    joinWorkspace($workspace, $member, SystemRole::Member);

    $channel = channelWithMember($workspace, $owner);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);
    $message = app(SendMessage::class)->fromWebhook($webhook, 'Oeps, per ongeluk');

    expect($member->can('delete', $message))->toBeFalse();
});

it('still refuses to let anyone delete another members message', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($owner, SystemRole::Admin);
    joinWorkspace($workspace, $member, SystemRole::Member);

    $channel = channelWithMember($workspace, $owner);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $message = app(SendMessage::class)->handle($channel, $member, 'Mijn woorden');

    expect($owner->can('delete', $message))->toBeFalse();
});

it('renders a bot message in the admin panel without a member behind it', function () {
    $admin = User::factory()->create(['admin_at' => now()]);
    $message = Message::factory()->fromBot()->create();

    actingAs($admin)
        ->get(route('filament.admin.resources.messages.view', $message))
        ->assertOk()
        ->assertSee($message->bot_name);
});
