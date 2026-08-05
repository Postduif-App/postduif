<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Features\AiAccess;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

/**
 * A member with a token, in a workspace that lets a token join in, with a
 * channel they are in.
 *
 * The feature is switched on by hand because that is what it is for: AI access
 * starts off, so a test that wants to post over the API has to say so — exactly
 * as a workspace does.
 *
 * @return array{0: User, 1: string, 2: Channel, 3: Workspace}
 */
function posterWithToken(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    Feature::for($workspace)->activate(AiAccess::class);

    $channel = channelWithMember($workspace, $user);

    [, $token] = tokenFor($user);

    return [$user, $token, $channel, $workspace];
}

it('says something in a channel and hands back the receipt', function () {
    [$user, $token, $channel] = posterWithToken();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Er is een storing bij de klant.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.channelId', $channel->id)
        ->assertJsonPath('data.body', 'Er is een storing bij de klant.')
        ->assertJsonPath('data.parentId', null);

    $message = $channel->messages()->latest('id')->first();

    /*
     * An ordinary message in every way: it is theirs, it carries their name,
     * and nothing marks it as having come from a script. They asked for it to
     * be sent.
     */
    expect($message->user_id)->toBe($user->id)
        ->and($message->body)->toBe('Er is een storing bij de klant.');
});

it('goes through the same action the screen does', function () {
    [$user, $token, $channel] = posterWithToken();

    $other = User::factory()->create();
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => "Hallo @{$other->username}",
        ])
        ->assertCreated();

    /*
     * The whole reason this does not write the row itself: a message that
     * skipped SendMessage would appear in nobody's sidebar and ping nobody.
     * The mention is the cheapest proof that it did not skip it.
     */
    expect(InboxItem::where('user_id', $other->id)->count())->toBe(1);
});

it('hangs a reply under a message that is already there', function () {
    [$user, $token, $channel] = posterWithToken();

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Opgelost.',
            'parent_id' => $parent->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.parentId', $parent->id);
});

it('will not hang a reply under a message from somewhere else', function () {
    [, $token, $channel] = posterWithToken();

    $elsewhere = Message::factory()->create();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Hoort hier niet.',
            'parent_id' => $elsewhere->id,
        ])
        ->assertJsonValidationErrors('parent_id');
});

it('answers the same way for a channel that is not there and one that is not yours', function () {
    [, $token] = posterWithToken();

    $theirs = Channel::factory()->create();

    $missing = withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), ['channel_id' => 99999, 'body' => 'Hoi'])
        ->assertNotFound();

    $forbidden = withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), ['channel_id' => $theirs->id, 'body' => 'Hoi'])
        ->assertNotFound();

    /*
     * Word for word the same. Telling the two apart would let a caller walk the
     * ids to find out which channels exist and where this person is.
     */
    expect($missing->json('message'))->toBe($forbidden->json('message'));
});

it('refuses a workspace that lets no token join in', function () {
    [$user, $token, $channel, $workspace] = posterWithToken();

    Feature::for($workspace)->deactivate(AiAccess::class);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Toch maar niet.',
        ])
        /*
         * And with the same sentence again. A different one — "AI access is
         * off" — would confirm that the channel exists, which is exactly what
         * the answer above refuses to do.
         */
        ->assertNotFound();

    expect($channel->messages()->count())->toBe(0);
});

it('holds a token to the rules of the channel it writes in', function () {
    [$user, $token, $channel] = posterWithToken();

    // A broadcast channel: only admins post, everybody may still answer.
    $channel->forceFill(['posting_policy' => ChannelPostingPolicy::Admins])->save();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Mag ik dit zeggen?',
        ])
        ->assertForbidden();

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    // Replying is a different right, and this member still has it.
    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'In de thread mag het wel.',
            'parent_id' => $parent->id,
        ])
        ->assertCreated();
});

it('says nothing at all without a token', function () {
    $channel = Channel::factory()->create();

    postJson(route('api.v1.messages.store'), ['channel_id' => $channel->id, 'body' => 'Hoi'])
        ->assertUnauthorized();
});

it('refuses an empty message and one longer than a message may be', function () {
    [, $token, $channel] = posterWithToken();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), ['channel_id' => $channel->id, 'body' => ''])
        ->assertJsonValidationErrors('body');

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => str_repeat('a', 4001),
        ])
        ->assertJsonValidationErrors('body');
});

it('will not post into an archived channel', function () {
    [, $token, $channel] = posterWithToken();

    $channel->forceFill(['archived_at' => now()])->save();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('api.v1.messages.store'), [
            'channel_id' => $channel->id,
            'body' => 'Is hier nog iemand?',
        ])
        ->assertStatus(422);
});

it('lists the channels a token may reach, and says which it may write in', function () {
    [$user, $token, $channel, $workspace] = posterWithToken();

    // One this member can see but has not joined: readable, not writable.
    Channel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'aankondigingen']);

    // And one in a workspace that lets no token in at all.
    $elsewhere = Workspace::factory()->create();
    $elsewhere->members()->attach($user->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    Channel::factory()->create(['workspace_id' => $elsewhere->id, 'name' => 'gesloten']);

    $listed = withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.v1.channels.index'))
        ->assertOk()
        ->json('data');

    expect(collect($listed)->pluck('name'))->toContain($channel->name)
        // The list never offers a channel the next call would refuse.
        ->not->toContain('gesloten')
        ->and(collect($listed)->firstWhere('name', $channel->name)['canPost'])->toBeTrue()
        ->and(collect($listed)->firstWhere('name', 'aankondigingen')['canPost'])->toBeFalse();
});
