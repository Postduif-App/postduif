<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A message in one channel, and a second channel to carry it to.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel, 4: Message}
 */
function forwardableMessage(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $source = channelWithMember($workspace, $user);
    $target = channelWithMember($workspace, $user);

    $author = User::factory()->create(['name' => 'Anna Bakker']);
    $source->members()->attach($author->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $source->id,
        'user_id' => $author->id,
        'body' => 'De levering is verzet naar dinsdag',
    ]);

    return [$user, $workspace, $source, $target, $message];
}

it('carries a message into another channel', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertRedirect();

    $forwarded = $target->messages()->sole();

    expect($forwarded->body)->toBe('De levering is verzet naar dinsdag')
        // Attribution, not authorship: the forwarder placed it.
        ->and($forwarded->user_id)->toBe($user->id)
        ->and($forwarded->forwarded_from)->toBe('Anna Bakker')
        // And the original stays exactly where it was.
        ->and($source->messages()->count())->toBe(1);
});

it('puts a note above what was forwarded', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
        'note' => 'Even voor jullie ter info',
    ]);

    expect($target->messages()->sole()->body)
        ->toBe("Even voor jullie ter info\n\nDe levering is verzet naar dinsdag");
});

/**
 * Two permissions, and both are needed. Reading where it comes from does not
 * make somewhere else a place you may put things.
 */
it('refuses a target this member may not post in', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    $target->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertForbidden();

    expect($target->messages()->count())->toBe(0);
});

it('refuses a source this member may not read', function () {
    [, $workspace, $source, $target, $message] = forwardableMessage();

    $source->update(['type' => ChannelType::Private]);

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => 'member', 'joined_at' => now()]);
    $target->members()->attach($outsider->id, ['joined_at' => now()]);

    actingAs($outsider)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertForbidden();
});

it('does not reach a channel in another workspace', function () {
    [$user, $workspace, $source, , $message] = forwardableMessage();

    $elsewhere = Channel::factory()->create();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

/** Route binding leaves trashed messages out, so it never resolves at all. */
it('refuses to forward something that was taken back', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    $message->delete();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertNotFound();
});

it('keeps the bot name as the attribution', function () {
    [$user, $workspace, $source, $target] = forwardableMessage();

    $fromBot = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $source->id,
        'user_id' => null,
        'bot_name' => 'Statuspagina',
        'body' => 'Storing opgelost',
    ]);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $fromBot]), [
        'channel_id' => $target->id,
    ]);

    expect($target->messages()->sole()->forwarded_from)->toBe('Statuspagina');
});
