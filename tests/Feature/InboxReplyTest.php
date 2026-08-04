<?php

use App\Actions\Chat\SendMessage;
use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\Message;

it('tells you when somebody answers your message', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question);

    $row = InboxItem::where('user_id', $asker->id)->sole();

    // Keyed on the question, not on the answer: that is what lets a second
    // reply land on this same row instead of beside it.
    expect($row->type)->toBe(InboxItemType::Reply)
        ->and($row->message_id)->toBe($question->id)
        ->and($row->actor_id)->toBe($answerer->id)
        ->and($row->read_at)->toBeNull();
});

it('makes a second answer bump the row rather than add one', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question);
    InboxItem::where('user_id', $asker->id)->update(['read_at' => now()]);

    reply($channel, $answerer, $question, 'En het staat nu ook in de handleiding');

    $rows = InboxItem::where('user_id', $asker->id)->get();

    // One line for five answers, and unread again because there is something
    // in it that was not there when it was last opened.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->read_at)->toBeNull();
});

it('says nothing when you answer yourself', function () {
    [$asker, , $channel, $question] = threadFixture();

    reply($channel, $asker, $question, 'Laat maar, gevonden');

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('does not say the same thing twice when the answer also names you', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, "Ja hoor @{$asker->username}, ik pak het op");

    // Being named is the stronger claim on somebody's attention; a reply row
    // beside it would be the same news in weaker words.
    $rows = InboxItem::where('user_id', $asker->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe(InboxItemType::Mention);
});

it('says nothing to somebody who has left the channel', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $channel->members()->detach($asker->id);

    reply($channel, $answerer, $question);

    // The message stays, but a row pointing into a conversation they can no
    // longer open would be a dead end.
    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('leaves a message that starts no thread out of it', function () {
    [$asker, $answerer, $channel] = threadFixture();

    app(SendMessage::class)->handle(
        channel: $channel,
        author: $answerer,
        body: 'Goedemorgen allemaal',
    );

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});
