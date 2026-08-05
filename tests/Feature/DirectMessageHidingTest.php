<?php

use App\Actions\Chat\SendMessage;
use App\Actions\Chat\StartDirectMessage;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * Two people in one workspace, with a conversation open between them.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function directFixture(): array
{
    $me = User::factory()->create();
    $workspace = workspaceWithMember($me);

    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);

    $channel = app(StartDirectMessage::class)->handle($workspace, $me, $other);

    return [$me, $other, $workspace, $channel];
}

it('takes a conversation out of your sidebar and leaves it in theirs', function () {
    [$me, $other, $workspace, $channel] = directFixture();

    actingAs($me)
        ->delete(route('chat.directs.destroy', [$workspace, $channel]))
        ->assertRedirect(route('chat.index', $workspace));

    actingAs($me)
        ->get(route('chat.index', $workspace))
        ->assertRedirect();

    // Nothing was deleted: the other side still has the whole conversation.
    actingAs($other)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('directMessages', 1));
});

it('leaves the sidebar row out for whoever put it away', function () {
    [$me, , $workspace, $channel] = directFixture();
    $room = channelWithMember($workspace, $me);

    actingAs($me)->delete(route('chat.directs.destroy', [$workspace, $channel]));

    actingAs($me)
        ->get(route('chat.show', [$workspace, $room]))
        ->assertInertia(fn ($page) => $page->has('directMessages', 0));
});

it('brings the conversation back when the other person writes again', function () {
    [$me, $other, $workspace, $channel] = directFixture();
    $room = channelWithMember($workspace, $me);

    actingAs($me)->delete(route('chat.directs.destroy', [$workspace, $channel]));

    app(SendMessage::class)->handle($channel, $other, 'Ben je er nog?');

    actingAs($me)
        ->get(route('chat.show', [$workspace, $room]))
        ->assertInertia(fn ($page) => $page->has('directMessages', 1));
});

it('brings it back when you open the conversation yourself', function () {
    [$me, , $workspace, $channel] = directFixture();

    actingAs($me)->delete(route('chat.directs.destroy', [$workspace, $channel]));

    actingAs($me)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('directMessages', 1));
});

it('brings it back when you pick that person again', function () {
    [$me, $other, $workspace, $channel] = directFixture();
    $room = channelWithMember($workspace, $me);

    actingAs($me)->delete(route('chat.directs.destroy', [$workspace, $channel]));

    actingAs($me)->post(route('chat.directs.store', $workspace), [
        'user_id' => $other->id,
    ])->assertRedirect(route('chat.show', [$workspace, $channel]));

    actingAs($me)
        ->get(route('chat.show', [$workspace, $room]))
        ->assertInertia(fn ($page) => $page->has('directMessages', 1));
});

it('refuses to hide a conversation somebody else is having', function () {
    [, , $workspace, $channel] = directFixture();

    $stranger = User::factory()->create();
    joinWorkspace($workspace, $stranger, SystemRole::Member);

    actingAs($stranger)
        ->delete(route('chat.directs.destroy', [$workspace, $channel]))
        ->assertForbidden();
});

it('is not the way to get rid of a channel', function () {
    [$me, , $workspace] = directFixture();
    $room = channelWithMember($workspace, $me);

    actingAs($me)
        ->delete(route('chat.directs.destroy', [$workspace, $room]))
        ->assertNotFound();
});
