<?php

use App\Actions\Chat\CountUnread;
use App\Actions\Chat\EditMessage;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A member, a channel they belong to, and a message in it.
 *
 * @return array{0: User, 1: Channel, 2: Message}
 */
function inboxFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    return [$user, $channel, $message];
}

it('lets one message reach the same member for more than one reason', function () {
    [$user, $channel, $message] = inboxFixture();

    foreach ([InboxItemType::Mention, InboxItemType::ThreadReply] as $type) {
        InboxItem::create([
            'type' => $type,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);
    }

    // The old unique was (message_id, user_id), which made this impossible:
    // being named in a thread you also follow would have been one row, and
    // whichever reason was written second would have been lost.
    expect(InboxItem::where('message_id', $message->id)->count())->toBe(2);
});

it('collapses a second event of the same kind onto the row that is already there', function () {
    [$user, $channel, $message] = inboxFixture();

    $key = [
        'type' => InboxItemType::ThreadReply,
        'message_id' => $message->id,
        'user_id' => $user->id,
    ];

    $first = InboxItem::updateOrCreate($key, [
        'channel_id' => $channel->id,
        'read_at' => now(),
    ]);

    InboxItem::updateOrCreate($key, ['channel_id' => $channel->id, 'read_at' => null]);

    // Twenty replies in one thread are one line to read, not twenty to scroll
    // past — and the row comes back unread, because there is something in it
    // that was not there when it was last opened.
    expect(InboxItem::where('message_id', $message->id)->count())->toBe(1)
        ->and($first->fresh()->read_at)->toBeNull();
});

it('refuses a duplicate reason at the database, not just in Eloquent', function () {
    [$user, $channel, $message] = inboxFixture();

    $row = [
        'type' => InboxItemType::Mention->value,
        'message_id' => $message->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('inbox_items')->insert($row);

    /*
     * updateOrCreate selects before it writes, so the collapsing above would
     * pass with no index at all. This goes straight past Eloquent to prove the
     * constraint is real — and it deliberately uses a mention, whose poll_id is
     * null: that is the row a single unique over both subject columns would
     * have waved through, because Postgres counts nulls as distinct.
     */
    expect(fn () => DB::table('inbox_items')->insert($row))
        ->toThrow(QueryException::class);
});

it('collapses votes onto one row per poll', function () {
    [$user, $channel] = inboxFixture();

    $poll = Poll::create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'question' => 'Donderdag of vrijdag?',
    ]);

    foreach (['Donderdag', 'Vrijdag'] as $position => $label) {
        PollOption::create(['poll_id' => $poll->id, 'label' => $label, 'position' => $position]);
    }

    $key = ['type' => InboxItemType::PollVote, 'poll_id' => $poll->id, 'user_id' => $user->id];

    foreach (range(1, 3) as $ignored) {
        InboxItem::updateOrCreate($key, ['channel_id' => $channel->id, 'read_at' => null]);
    }

    // Forty voters on one poll would otherwise be forty rows, which is the
    // point at which an inbox stops being one.
    expect(InboxItem::where('poll_id', $poll->id)->count())->toBe(1);
});

it('keeps a row that points at a poll rather than a message', function () {
    [$user, $channel] = inboxFixture();

    $poll = Poll::create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'question' => 'Wie sluit af?',
    ]);

    $row = InboxItem::create([
        'type' => InboxItemType::PollVote,
        'poll_id' => $poll->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
    ]);

    // A poll is reached through a URL in a message body, so there is no message
    // to hang the row off — message_id has to be allowed to stay empty.
    expect($row->refresh()->message_id)->toBeNull()
        ->and($row->poll->question)->toBe('Wie sluit af?');
});

it('leaves the other kinds alone when a message is edited', function () {
    [$user, $channel, $message] = inboxFixture();

    $follower = User::factory()->create();
    $channel->members()->attach($follower->id, ['joined_at' => now()]);

    InboxItem::create([
        'type' => InboxItemType::ThreadReply,
        'message_id' => $message->id,
        'user_id' => $follower->id,
        'channel_id' => $channel->id,
    ]);

    app(EditMessage::class)->handle($message, 'Toch maar niet, laat maar zitten');

    /*
     * The prune inside EditMessage is what keeps mentions in step with the
     * text. Unscoped it would reach the replies too, so fixing a typo in the
     * opening post would quietly empty the inbox of everyone in the thread.
     */
    expect(InboxItem::where('user_id', $follower->id)->count())->toBe(1);
});

it('counts only mentions towards the badge beside a channel', function () {
    [$user, $channel, $message] = inboxFixture();

    foreach ([InboxItemType::Mention, InboxItemType::ThreadReply, InboxItemType::Reply] as $type) {
        InboxItem::create([
            'type' => $type,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'channel_id' => $channel->id,
        ]);
    }

    ['mentions' => $mentions] = app(CountUnread::class)->handle($user, collect([$channel->id]));

    // A thread carrying on is worth a line in the inbox, but a number beside a
    // channel name reads as "somebody asked you something".
    expect($mentions[$channel->id])->toBe(1);
});
