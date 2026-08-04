<?php

use App\Actions\Chat\DispatchScheduledBroadcasts;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledBroadcast;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * An announcement waiting for two channels.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel, 4: ScheduledBroadcast}
 */
function broadcastFixture(string $sendAt = '-1 minute'): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);

    $first = channelWithMember($workspace, $author);
    $second = channelWithMember($workspace, $author);

    $broadcast = ScheduledBroadcast::create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'body' => 'Morgen is het kantoor dicht',
        'send_at' => now()->modify($sendAt),
    ]);

    $broadcast->channels()->attach([$first->id, $second->id]);

    return [$author, $workspace, $first, $second, $broadcast];
}

it('posts one announcement in every channel it was meant for', function () {
    [, , $first, $second, $broadcast] = broadcastFixture();

    expect(app(DispatchScheduledBroadcasts::class)->handle())
        ->toBe(['sent' => 1, 'failed' => 0]);

    foreach ([$first, $second] as $channel) {
        expect(Message::where('channel_id', $channel->id)
            ->where('body', 'Morgen is het kantoor dicht')
            ->exists())->toBeTrue();
    }

    expect($broadcast->fresh()->isPending())->toBeFalse();
});

it('leaves an announcement whose moment has not come', function () {
    [, , $first, , $broadcast] = broadcastFixture('+1 hour');

    expect(app(DispatchScheduledBroadcasts::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 0]);

    expect(Message::where('channel_id', $first->id)->exists())->toBeFalse()
        ->and($broadcast->fresh()->isPending())->toBeTrue();
});

it('asks about posting rights now, not when it was scheduled', function () {
    [, , $first, $second, $broadcast] = broadcastFixture();

    /*
     * The whole reason this is one row rather than one per channel. Fanning out
     * at scheduling time would have frozen the answer a week early and posted
     * into a channel the sender may no longer write in.
     */
    $second->forceFill([
        'posting_policy' => ChannelPostingPolicy::Admins,
        // Somebody else made it, and the author is an ordinary member — the
        // two ways ChannelPostingPolicy::Admins lets you through.
        'created_by' => User::factory()->create()->id,
    ])->save();

    app(DispatchScheduledBroadcasts::class)->handle();

    expect(Message::where('channel_id', $first->id)->exists())->toBeTrue()
        ->and(Message::where('channel_id', $second->id)->exists())->toBeFalse();
});

it('skips an archived channel without losing the rest', function () {
    [, , $first, $second] = broadcastFixture();

    $second->forceFill(['archived_at' => now()])->save();

    expect(app(DispatchScheduledBroadcasts::class)->handle())
        ->toBe(['sent' => 1, 'failed' => 0]);

    // One closed channel out of two is not a reason for the other to go unsaid.
    expect(Message::where('channel_id', $first->id)->exists())->toBeTrue()
        ->and(Message::where('channel_id', $second->id)->exists())->toBeFalse();
});

it('says so when there was nowhere left to post', function () {
    [, , $first, $second, $broadcast] = broadcastFixture();

    foreach ([$first, $second] as $channel) {
        $channel->forceFill(['archived_at' => now()])->save();
    }

    expect(app(DispatchScheduledBroadcasts::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 1]);

    /*
     * Recorded as failed rather than quietly counted as sent: somebody who
     * scheduled an announcement wants to know it never landed.
     */
    $broadcast = $broadcast->fresh();

    expect($broadcast->failed_at)->not->toBeNull()
        ->and($broadcast->sent_at)->toBeNull()
        ->and($broadcast->failure_reason)->not->toBeEmpty();
});

it('never sends the same announcement twice', function () {
    [, , $first] = broadcastFixture();

    app(DispatchScheduledBroadcasts::class)->handle();
    app(DispatchScheduledBroadcasts::class)->handle();

    // The row is stamped before anything is posted, so a second run finds
    // nothing left to do — an announcement in six channels is the last thing
    // that should arrive twice.
    expect(Message::where('channel_id', $first->id)->count())->toBe(1);
});

it('does not retry one it has given up on', function () {
    [, , $first, $second, $broadcast] = broadcastFixture();

    foreach ([$first, $second] as $channel) {
        $channel->forceFill(['archived_at' => now()])->save();
    }

    app(DispatchScheduledBroadcasts::class)->handle();

    // Retrying forever would have a broken announcement knocking every minute
    // until somebody noticed.
    expect(app(DispatchScheduledBroadcasts::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 0])
        ->and($broadcast->fresh()->isPending())->toBeFalse();
});

it('reports what it did on the command line', function () {
    broadcastFixture();

    $this->artisan('chat:dispatch-broadcasts')
        ->expectsOutputToContain('1 rondzending verstuurd.')
        ->assertSuccessful();
});

it('schedules from the broadcast endpoint instead of sending', function () {
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $channel = channelWithMember($workspace, $author);

    actingAs($author)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Morgen is het kantoor dicht',
        'channels' => [$channel->id],
        'send_at' => now()->addHour()->toDateTimeString(),
    ])->assertRedirect();

    expect(Message::where('channel_id', $channel->id)->exists())->toBeFalse()
        ->and(ScheduledBroadcast::sole()->channels)->toHaveCount(1);
});

it('still sends straight away when no moment is given', function () {
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $channel = channelWithMember($workspace, $author);

    actingAs($author)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Nu meteen',
        'channels' => [$channel->id],
    ])->assertRedirect();

    expect(Message::where('channel_id', $channel->id)->exists())->toBeTrue()
        ->and(ScheduledBroadcast::count())->toBe(0);
});

it('refuses a moment that has already passed', function () {
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $channel = channelWithMember($workspace, $author);

    /*
     * Rather than quietly sending it now. Somebody who typed yesterday's date
     * made a mistake, and sending anyway would hide it.
     */
    actingAs($author)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Te laat',
        'channels' => [$channel->id],
        'send_at' => now()->subHour()->toDateTimeString(),
    ])->assertSessionHasErrors('send_at');

    expect(ScheduledBroadcast::count())->toBe(0);
});

it('keeps a channel out that the sender cannot see', function () {
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);
    $mine = channelWithMember($workspace, $author);

    $theirs = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    // The visibility filter runs before anything is scheduled, so a tag or a
    // guessed id cannot put an announcement somewhere out of reach.
    actingAs($author)->post(route('chat.broadcast.store', $workspace), [
        'body' => 'Alleen waar ik mag komen',
        'channels' => [$mine->id, $theirs->id],
        'send_at' => now()->addHour()->toDateTimeString(),
    ])->assertRedirect();

    expect(ScheduledBroadcast::sole()->channels->pluck('id')->all())->toBe([$mine->id]);
});

it('lists what this member has waiting', function () {
    [$author, $workspace, $first] = broadcastFixture('+1 hour');
    $channel = channelWithMember($workspace, $author);

    actingAs($author)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scheduledBroadcasts', 1)
            ->where('scheduledBroadcasts.0.body', 'Morgen is het kantoor dicht')
            ->has('scheduledBroadcasts.0.channels', 2));
});

it('keeps a colleague announcement out of that list', function () {
    [, $workspace] = broadcastFixture('+1 hour');

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);
    $channel = channelWithMember($workspace, $colleague);

    // Only your own: this list exists to answer "did I schedule that?", and
    // somebody else's announcement is not yours to stop.
    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('scheduledBroadcasts', 0));
});

it('drops one that has already gone out', function () {
    [$author, $workspace] = broadcastFixture();
    $channel = channelWithMember($workspace, $author);

    app(DispatchScheduledBroadcasts::class)->handle();

    // Nothing left to stop, so nothing to show.
    actingAs($author)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('scheduledBroadcasts', 0));
});

it('lets the sender withdraw one', function () {
    [$author, $workspace, , , $broadcast] = broadcastFixture('+1 hour');

    actingAs($author)
        ->delete(route('chat.broadcast.destroy', [$workspace, $broadcast]))
        ->assertRedirect();

    expect(ScheduledBroadcast::count())->toBe(0);
});

it('refuses to withdraw somebody else announcement', function () {
    [, $workspace, , , $broadcast] = broadcastFixture('+1 hour');

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($colleague)
        ->delete(route('chat.broadcast.destroy', [$workspace, $broadcast]))
        ->assertNotFound();

    expect(ScheduledBroadcast::count())->toBe(1);
});

it('refuses to withdraw one that already went out', function () {
    [$author, $workspace, , , $broadcast] = broadcastFixture();

    app(DispatchScheduledBroadcasts::class)->handle();

    /*
     * An announcement that has landed in six channels cannot be taken back,
     * and pretending otherwise would be worse than saying so.
     */
    actingAs($author)
        ->delete(route('chat.broadcast.destroy', [$workspace, $broadcast]))
        ->assertStatus(409);
});
