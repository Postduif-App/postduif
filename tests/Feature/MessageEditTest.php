<?php

use App\Actions\Chat\SendMessage;
use App\Events\ChannelActivity;
use App\Events\MessageEdited;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

it('rewrites your own message and marks it as edited', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Morgen om tien uur',
    ]);

    expect($message->edited_at)->toBeNull();

    actingAs($user)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Morgen om elf uur',
        ])
        ->assertRedirect();

    expect($message->fresh())
        ->body->toBe('Morgen om elf uur')
        ->edited_at->not->toBeNull();
});

it('refuses to let anybody rewrite somebody else', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $workspace->members()->attach($other->id, ['role' => 'member', 'joined_at' => now()]);
    $channel = channelWithMember($workspace, $author);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
        'body' => 'Mijn woorden',
    ]);

    actingAs($other)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Andermans woorden',
        ])
        ->assertForbidden();

    expect($message->fresh()->body)->toBe('Mijn woorden');
});

it('refuses to rewrite a message a bot posted', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $webhook = Webhook::factory()->create(['channel_id' => $channel->id]);

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Build geslaagd');

    actingAs($user)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Build mislukt',
        ])
        ->assertForbidden();

    expect($message->fresh()->body)->toBe('Build geslaagd');
});

it('refuses to rewrite a message that is already gone', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $message->delete();

    actingAs($user)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Toch nog wat',
        ])
        // Route model binding never resolves a soft-deleted message, so this
        // stops one step earlier than the policy would have.
        ->assertNotFound();
});

it('refuses an empty or overlong body', function (string $body) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Het origineel',
    ]);

    actingAs($user)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => $body,
        ])
        ->assertSessionHasErrors('body');

    expect($message->fresh()->body)->toBe('Het origineel');
})->with([
    'leeg' => '',
    'te lang' => fn () => str_repeat('a', 4001),
]);

it('tells the channel about the new text', function () {
    Event::fake([MessageEdited::class]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'De nieuwe tekst',
        ])
        ->assertRedirect();

    Event::assertDispatched(MessageEdited::class, function (MessageEdited $event) use ($channel) {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === (new PresenceChannel('chat.channel.'.$channel->id))->name
            && $payload['message']['body'] === 'De nieuwe tekst'
            && $payload['message']['editedAt'] !== null;
    });
});

it('records a mention the edit adds', function () {
    Event::fake([ChannelActivity::class]);

    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'fenna']);
    $workspace = workspaceWithMember($author);
    $workspace->members()->attach($mentioned->id, ['role' => 'member', 'joined_at' => now()]);
    $channel = channelWithMember($workspace, $author);
    $channel->members()->attach($mentioned->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
        'body' => 'Wie pakt dit op',
    ]);

    actingAs($author)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Wie pakt dit op @fenna',
        ])
        ->assertRedirect();

    expect(InboxItem::where('message_id', $message->id)->where('user_id', $mentioned->id)->exists())
        ->toBeTrue();

    Event::assertDispatched(ChannelActivity::class, fn (ChannelActivity $event) => $event->userId === $mentioned->id && $event->mentioned);
});

it('drops the mention when the edit takes the handle out', function () {
    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'fenna']);
    $workspace = workspaceWithMember($author);
    $workspace->members()->attach($mentioned->id, ['role' => 'member', 'joined_at' => now()]);
    $channel = channelWithMember($workspace, $author);
    $channel->members()->attach($mentioned->id, ['joined_at' => now()]);

    $message = app(SendMessage::class)->handle($channel, $author, 'Kijk jij even @fenna');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(1);

    actingAs($author)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Laat maar, ik doe het zelf',
        ])
        ->assertRedirect();

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0);
});

it('leaves an unchanged mention alone rather than notifying again', function () {
    $author = User::factory()->create();
    $mentioned = User::factory()->create(['username' => 'fenna']);
    $workspace = workspaceWithMember($author);
    $workspace->members()->attach($mentioned->id, ['role' => 'member', 'joined_at' => now()]);
    $channel = channelWithMember($workspace, $author);
    $channel->members()->attach($mentioned->id, ['joined_at' => now()]);

    $message = app(SendMessage::class)->handle($channel, $author, 'Kijk jij even @fenna');
    $before = InboxItem::where('message_id', $message->id)->firstOrFail();

    // Faked only now: sending the original legitimately notified Fenna, and it
    // is the edit that must stay quiet.
    Event::fake([ChannelActivity::class]);

    actingAs($author)
        ->patch(route('chat.messages.update', [$workspace, $channel, $message]), [
            'body' => 'Kijk jij even mee @fenna',
        ])
        ->assertRedirect();

    $after = InboxItem::where('message_id', $message->id)->firstOrFail();

    // Same row, so whatever read state it carried survived the edit.
    expect($after->id)->toBe($before->id);

    Event::assertNotDispatched(ChannelActivity::class);
});
