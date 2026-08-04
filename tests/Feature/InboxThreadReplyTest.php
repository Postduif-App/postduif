<?php

use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\User;

/**
 * Somebody who joined the thread without starting it.
 */
function joinsThread(mixed $channel, mixed $question): User
{
    $bystander = User::factory()->create();
    $channel->workspace->members()->attach($bystander->id, ['joined_at' => now()]);
    $channel->members()->attach($bystander->id, ['joined_at' => now()]);

    reply($channel, $bystander, $question, 'Ik kijk mee');

    return $bystander;
}

it('tells everyone who has spoken in a thread that it carried on', function () {
    [, $answerer, $channel, $question] = threadFixture();

    $bystander = joinsThread($channel, $question);

    reply($channel, $answerer, $question, 'Het staat in de handleiding');

    $row = InboxItem::where('user_id', $bystander->id)->sole();

    expect($row->type)->toBe(InboxItemType::ThreadReply)
        ->and($row->message_id)->toBe($question->id)
        ->and($row->actor_id)->toBe($answerer->id);
});

it('gives the thread starter the stronger reason, not both', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    joinsThread($channel, $question);
    reply($channel, $answerer, $question, 'En hier is het antwoord');

    $rows = InboxItem::where('user_id', $asker->id)->get();

    // Being answered is a question put to you; the thread carrying on is news.
    // Two rows would say the same thing twice, once weakly.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe(InboxItemType::Reply);
});

it('says nothing to the person who just wrote the reply', function () {
    [, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, 'Eerste');
    reply($channel, $answerer, $question, 'En nog wat');

    expect(InboxItem::where('user_id', $answerer->id)->count())->toBe(0);
});

it('prefers a mention over a thread row', function () {
    [, $answerer, $channel, $question] = threadFixture();

    $bystander = joinsThread($channel, $question);

    reply($channel, $answerer, $question, "Klopt dat @{$bystander->username}?");

    $rows = InboxItem::where('user_id', $bystander->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe(InboxItemType::Mention);
});

it('collapses a busy thread onto one row per person', function () {
    [, $answerer, $channel, $question] = threadFixture();

    $bystander = joinsThread($channel, $question);

    foreach (range(1, 5) as $number) {
        reply($channel, $answerer, $question, "Aanvulling {$number}");
    }

    // Five replies, one line. This is the whole reason the row is keyed on the
    // question rather than on the answer.
    expect(InboxItem::where('user_id', $bystander->id)->count())->toBe(1);
});

it('says nothing to somebody who has left the channel', function () {
    [, $answerer, $channel, $question] = threadFixture();

    $bystander = joinsThread($channel, $question);
    $channel->members()->detach($bystander->id);

    reply($channel, $answerer, $question, 'Nog een aanvulling');

    expect(InboxItem::where('user_id', $bystander->id)->count())->toBe(0);
});
