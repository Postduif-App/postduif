<?php

use App\Actions\Chat\ChannelPresence;
use App\Actions\Chat\SendMessage;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * An author who runs the workspace, two other members, and a channel they are
 * all in.
 *
 * @return array{0: User, 1: User, 2: User, 3: Workspace, 4: Channel}
 */
function channelWithThree(): array
{
    $author = User::factory()->create(['username' => 'author']);
    $one = User::factory()->create(['username' => 'one']);
    $two = User::factory()->create(['username' => 'two']);

    $workspace = workspaceWithMember($author, SystemRole::Owner);
    foreach ([$one, $two] as $member) {
        joinWorkspace($workspace, $member, SystemRole::Member);
    }

    $channel = channelWithMember($workspace, $author);
    $channel->members()->attach([$one->id, $two->id], ['joined_at' => now()]);

    return [$author, $one, $two, $workspace, $channel];
}

/** Pretend these users have the channel open. */
function pretendPresent(array $users): void
{
    $presence = Mockery::mock(ChannelPresence::class);
    $presence->shouldReceive('handle')
        ->andReturn(new Collection(array_map(fn (User $u) => $u->id, $users)));

    app()->instance(ChannelPresence::class, $presence);
}

function mentionedBy(Channel $channel, User $author, string $body): array
{
    $message = app(SendMessage::class)->handle($channel, $author, $body);

    return InboxItem::where('message_id', $message->id)->pluck('user_id')->sort()->values()->all();
}

it('reaches every other channel member with everyone', function () {
    [$author, $one, $two, , $channel] = channelWithThree();

    expect(mentionedBy($channel, $author, 'Let op @everyone'))
        ->toBe(collect([$one->id, $two->id])->sort()->values()->all());
});

it('never notifies the author of their own broadcast', function () {
    [$author, , , , $channel] = channelWithThree();

    expect(mentionedBy($channel, $author, 'Hoi @everyone'))->not->toContain($author->id);
});

it('leaves out workspace members who are not in the channel', function () {
    [$author, $one, $two, $workspace, $channel] = channelWithThree();
    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    expect(mentionedBy($channel, $author, '@everyone even kijken'))
        ->toBe(collect([$one->id, $two->id])->sort()->values()->all())
        ->not->toContain($outsider->id);
});

it('reaches only the members who have the channel open with here', function () {
    [$author, $one, $two, , $channel] = channelWithThree();
    pretendPresent([$one]);

    expect(mentionedBy($channel, $author, 'Wie is er @here?'))
        ->toBe([$one->id])
        ->not->toContain($two->id);
});

it('reaches nobody with here when nobody is looking', function () {
    [$author, , , , $channel] = channelWithThree();
    pretendPresent([]);

    expect(mentionedBy($channel, $author, '@here iemand?'))->toBe([]);
});

/**
 * The message still sends and still reads sensibly; it simply notifies nobody.
 * Refusing the whole message over one word would lose what somebody just typed.
 */
it('notifies nobody when the author may not broadcast', function () {
    [$author, , , $workspace, $channel] = channelWithThree();
    setAbility($workspace, WorkspaceAbility::BroadcastMention, false);

    $message = app(SendMessage::class)->handle($channel, $author, 'Toch maar @everyone');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0)
        ->and($message->body)->toBe('Toch maar @everyone');
});

it('lets a plain member broadcast once the workspace opens it up', function () {
    [, $one, $two, $workspace, $channel] = channelWithThree();

    expect(mentionedBy($channel, $one, 'Mag ik @everyone'))->toBe([]);

    setAbility($workspace, WorkspaceAbility::BroadcastMention, true);

    expect(mentionedBy($channel, $one, 'En nu @everyone'))->toContain($two->id);
});

it('combines a broadcast with a personal mention without duplicating anyone', function () {
    [$author, $one, $two, , $channel] = channelWithThree();

    $message = app(SendMessage::class)->handle($channel, $author, '@one en ook @everyone');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(2)
        ->and(InboxItem::where('message_id', $message->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$one->id, $two->id])->sort()->values()->all());
});

it('treats the group handles as ordinary text mid-word', function () {
    [$author, , , , $channel] = channelWithThree();

    expect(mentionedBy($channel, $author, 'zie ook iets@everyone hier'))->toBe([]);
});

it('never hands out a reserved handle at registration', function () {
    User::query()->delete();

    $this->post(route('register'), [
        'name' => 'Here',
        'email' => 'here@example.com',
        'password' => 'wachtwoord-voor-test',
        'password_confirmation' => 'wachtwoord-voor-test',
    ]);

    expect(User::value('username'))->not->toBe('here');
});
