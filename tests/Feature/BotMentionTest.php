<?php

use App\Actions\Chat\SendMessage;
use App\Enums\BroadcastMentionPolicy;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Webhook;

use function Pest\Laravel\actingAs;

/**
 * A bot is not a member, so nothing in the mention system can resolve it. These
 * tests exist to keep it that way: the property is easy to lose by accident the
 * moment someone decides a bot needs to appear in a list somewhere.
 */
it('leaves webhooks out of the data the autocomplete is built from', function () {
    $user = User::factory()->create(['username' => 'fenna']);
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.members', fn ($members) => collect($members)
                ->pluck('username')
                ->all() === ['fenna']
            )
        );
});

it('records no mention for a message naming a bot', function () {
    $user = User::factory()->create(['username' => 'fenna']);
    $channel = channelWithMember(workspaceWithMember($user), $user);

    Webhook::factory()->for($channel)->create(['bot_name' => 'buildbot']);

    $message = app(SendMessage::class)->handle($channel, $user, 'Hoi @buildbot, hoe staat het?');

    expect(InboxItem::where('message_id', $message->id)->exists())->toBeFalse();
});

/**
 * The nastiest case: a bot named after an actual member. The handle must still
 * resolve to the person, because handles have only ever meant usernames — a bot
 * name is a display name and never enters that namespace.
 */
it('resolves a handle to the member even when a bot shares the name', function () {
    $writer = User::factory()->create(['username' => 'writer']);
    $fenna = User::factory()->create(['username' => 'fenna', 'name' => 'Fenna']);

    $workspace = workspaceWithMember($writer);
    $workspace->members()->attach($fenna->id, ['role' => 'member', 'joined_at' => now()]);

    $channel = channelWithMember($workspace, $writer);
    $channel->members()->attach($fenna->id, ['joined_at' => now()]);

    Webhook::factory()->for($channel)->create(['bot_name' => 'fenna']);

    $message = app(SendMessage::class)->handle($channel, $writer, 'Kijk even @fenna');

    expect(InboxItem::where('message_id', $message->id)->pluck('user_id')->all())
        ->toBe([$fenna->id]);
});

it('lets a bot mention a member without being mentionable itself', function () {
    $user = User::factory()->create(['username' => 'fenna']);
    $channel = channelWithMember(workspaceWithMember($user), $user);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    $message = app(SendMessage::class)->fromWebhook($webhook, 'De build faalt, @fenna');

    expect(InboxItem::where('message_id', $message->id)->pluck('user_id')->all())
        ->toBe([$user->id]);
});

/**
 * @everyone and @here are refused for a bot regardless of the workspace policy,
 * including the most permissive one — there is no member behind a webhook to
 * weigh that policy against.
 */
it('refuses @everyone from a bot even when the workspace allows it', function () {
    $user = User::factory()->create(['username' => 'fenna']);
    $workspace = workspaceWithMember($user);
    $workspace->forceFill(['broadcast_mentions' => BroadcastMentionPolicy::Everyone])->save();

    $channel = channelWithMember($workspace, $user);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Let op @everyone');

    expect(InboxItem::where('message_id', $message->id)->exists())->toBeFalse();
});

it('refuses @here from a bot', function () {
    $user = User::factory()->create(['username' => 'fenna']);
    $channel = channelWithMember(workspaceWithMember($user), $user);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Even kijken @here');

    expect(InboxItem::where('message_id', $message->id)->exists())->toBeFalse();
});
