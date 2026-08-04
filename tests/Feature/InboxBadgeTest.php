<?php

use App\Actions\Chat\SendMessage;
use App\Actions\Polls\CastVote;
use App\Events\InboxUpdated;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

it('tells the thread starter how much is waiting', function () {
    Event::fake([InboxUpdated::class]);

    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, 'Ik pak het op');

    // The count, not a nudge to add one: rows collapse, so a client counting
    // events upwards would climb away from the truth.
    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->userId === $asker->id
        && $event->workspaceId === $channel->workspace_id
        && $event->unread === 1);
});

it('does not move when a second reply lands on the same row', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, 'Ik pak het op');

    Event::fake([InboxUpdated::class]);

    reply($channel, $answerer, $question, 'En het staat in de handleiding');

    // Still one row, so still one waiting — the badge has nowhere to go.
    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->userId === $asker->id
        && $event->unread === 1);
});

it('tells the asker when their poll is answered', function () {
    Event::fake([InboxUpdated::class]);

    [$asker, $voter, $poll, $thursday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $voter);

    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->userId === $asker->id
        && $event->unread === 1);
});

it('counts every kind towards the number on the inbox button', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    // Two separate events, because one message can only ever produce one row:
    // a reply that also names you is a mention and nothing else.
    reply($channel, $answerer, $question, 'Ik pak het op');

    app(SendMessage::class)->handle(
        channel: $channel,
        author: $answerer,
        body: "Even los hiervan @{$asker->username}, heb jij de sleutel?",
    );

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(2);

    // The badge beside the channel name counts only the mention; the one on
    // the inbox button stands for the whole list behind it.
    actingAs($asker)
        ->get(route('chat.inbox.index', $channel->workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inboxUnread', 2)
            ->where('channels.0.mentionCount', 1));
});
