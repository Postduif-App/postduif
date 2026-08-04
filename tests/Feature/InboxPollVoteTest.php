<?php

use App\Actions\Polls\CastVote;
use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\User;

it('tells the asker that somebody answered', function () {
    [$asker, $voter, $poll, $thursday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $voter);

    $row = InboxItem::where('user_id', $asker->id)->sole();

    expect($row->type)->toBe(InboxItemType::PollVote)
        ->and($row->poll_id)->toBe($poll->id)
        ->and($row->message_id)->toBeNull()
        // One row standing for every vote, so naming the latest voter would
        // put a name on a line that speaks for all of them.
        ->and($row->actor_id)->toBeNull();
});

it('keeps a busy poll to one row', function () {
    [$asker, $voter, $poll, $thursday] = pollFixture(allowsMultiple: true);

    app(CastVote::class)->handle($poll, $thursday, $voter);

    $others = User::factory()->count(3)->create();

    foreach ($others as $other) {
        $poll->channel->workspace->members()->attach($other->id, ['joined_at' => now()]);
        $poll->channel->members()->attach($other->id, ['joined_at' => now()]);

        app(CastVote::class)->handle($poll, $thursday, $other);
    }

    // Forty voters would otherwise be forty lines, which is the point at which
    // an inbox stops being one.
    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(1);
});

it('says nothing when a vote is taken back', function () {
    [$asker, $voter, $poll, $thursday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $voter);
    InboxItem::where('user_id', $asker->id)->delete();

    // The same gesture is the way out, and nothing happened worth reporting.
    expect(app(CastVote::class)->handle($poll, $thursday, $voter))->toBeFalse()
        ->and(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('says nothing when you answer your own question', function () {
    [$asker, , $poll, $thursday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $asker);

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('brings the row back when somebody changes their mind', function () {
    [$asker, $voter, $poll, $thursday, $friday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $voter);
    InboxItem::where('user_id', $asker->id)->update(['read_at' => now()]);

    app(CastVote::class)->handle($poll, $friday, $voter);

    // The answer is not what it was when the asker last looked, so the row has
    // something in it again.
    expect(InboxItem::where('user_id', $asker->id)->sole()->read_at)->toBeNull();
});

it('says nothing to an asker who has left the channel', function () {
    [$asker, $voter, $poll, $thursday] = pollFixture();

    $poll->channel->members()->detach($asker->id);

    app(CastVote::class)->handle($poll, $thursday, $voter);

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});
