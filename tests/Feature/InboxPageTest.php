<?php

use App\Actions\Polls\CastVote;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * One row of every kind that points at a message, for one member.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function inboxWithEveryKind(): array
{
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, 'Ik pak het op');

    foreach ([InboxItemType::Mention, InboxItemType::ThreadReply] as $type) {
        InboxItem::create([
            'type' => $type,
            'message_id' => Message::factory()->create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'user_id' => $answerer->id,
            ])->id,
            'user_id' => $asker->id,
            'channel_id' => $channel->id,
        ]);
    }

    return [$asker, $channel->workspace, $channel];
}

it('shows every kind at once when no tab is chosen', function () {
    [$asker, $workspace] = inboxWithEveryKind();

    actingAs($asker)
        ->get(route('chat.inbox.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/inbox')
            ->where('filter', null)
            ->has('items', 3));
});

it('narrows to the tab that was asked for', function () {
    [$asker, $workspace] = inboxWithEveryKind();

    actingAs($asker)
        ->get(route('chat.inbox.index', $workspace).'?type=reply')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', 'reply')
            ->has('items', 1)
            ->where('items.0.type', 'reply'));
});

it('treats a tab nobody offers as no tab at all', function () {
    [$asker, $workspace] = inboxWithEveryKind();

    // A hand-typed query string should land somewhere sensible rather than on
    // an empty list that looks like an inbox with nothing in it.
    actingAs($asker)
        ->get(route('chat.inbox.index', $workspace).'?type=verzonnen')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', null)
            ->has('items', 3));
});

it('keeps the mentions address pointing at the mentions tab', function () {
    [$asker, $workspace] = inboxWithEveryKind();

    // Where the sidebar badge has always pointed, and where bookmarks still go.
    actingAs($asker)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/inbox')
            ->where('filter', 'mention')
            ->has('items', 1)
            ->where('items.0.type', 'mention'));
});

it('carries a poll row without a message to point at', function () {
    [$asker, $voter, $poll, $thursday] = pollFixture();

    app(CastVote::class)->handle($poll, $thursday, $voter);

    actingAs($asker)
        ->get(route('chat.inbox.index', $poll->channel->workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where('items.0.type', 'poll-vote')
            ->where('items.0.poll.question', 'Donderdag of vrijdag?')
            ->where('items.0.poll.voterCount', 1)
            // Nobody is named: the row stands for every vote at once.
            ->where('items.0.actor', null));
});
