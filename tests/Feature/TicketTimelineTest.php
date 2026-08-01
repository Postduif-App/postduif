<?php

use App\Actions\Tickets\CommentOnTicket;
use App\Actions\Tickets\PresentTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketComment;

it('records a status change and clears the closing date on reopening', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Closed, $member);
    expect($ticket->fresh()->closed_at)->not->toBeNull();

    app(UpdateTicket::class)->status($ticket, TicketStatus::Open, $member);

    expect($ticket->fresh()->closed_at)->toBeNull()
        ->and($ticket->events()->where('type', TicketEventType::StatusChanged)->count())->toBe(2);
});

it('says nothing when the status was already what was asked for', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->status(TicketStatus::Waiting)->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Waiting, $member);

    expect($ticket->events()->count())->toBe(0);
});

it('starts a ticket when somebody picks it up', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->assign($ticket, $member, $member);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->assigned_to)->toBe($member->id)
        ->and($ticket->events()->where('type', TicketEventType::Assigned)->exists())->toBeTrue();
});

it('leaves a status alone that somebody already moved on', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->status(TicketStatus::Waiting)->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->assign($ticket, $member, $member);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Waiting);
});

it('stamps the first answer from somebody other than the opener', function () {
    [$member, $guest, , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    app(CommentOnTicket::class)->handle($ticket, $guest, 'nog een aanvulling');
    expect($ticket->fresh()->first_responded_at)->toBeNull();

    app(CommentOnTicket::class)->handle($ticket, $member, 'we kijken ernaar');

    expect($ticket->fresh()->first_responded_at)->not->toBeNull();
});

it('merges comments and events into one chronological timeline', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    $this->travelTo(now()->subMinutes(10));
    app(CommentOnTicket::class)->handle($ticket, $member, 'eerste reactie');

    $this->travelTo(now()->addMinutes(5));
    app(UpdateTicket::class)->status($ticket, TicketStatus::Waiting, $member);

    $this->travelBack();

    $timeline = app(PresentTicket::class)->timeline($ticket->fresh());

    expect(array_column($timeline, 'kind'))->toBe(['comment', 'event']);
});

it('keeps a withdrawn comment in place without its words', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    TicketComment::factory()->on($ticket)->by($member)->create(['body' => 'geheim'])->delete();

    $timeline = app(PresentTicket::class)->timeline($ticket->fresh());

    expect($timeline)->toHaveCount(1)
        ->and($timeline[0]['deleted'])->toBeTrue()
        ->and($timeline[0]['body'])->toBe('');
});

it('masks blocked words in a ticket the way it does in a message', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $workspace->update(['blocked_words' => ['kut']]);

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'title' => 'kut printer',
        'body' => 'die kut printer weer',
        'opened_by' => $member->id,
    ]);

    $payload = app(PresentTicket::class)->handle($ticket);

    expect($payload['title'])->not->toContain('kut')
        ->and($payload['body'])->not->toContain('kut');
});

it('still says where a ticket came from after the message is gone', function () {
    [$member, , , $channel] = ticketFixture();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'user_id' => $member->id,
        'body' => 'de printer doet het niet',
    ]);

    $ticket = Ticket::factory()->promotedFrom($message)->create(['opened_by' => $member->id]);
    $message->delete();

    $source = app(PresentTicket::class)->handle($ticket->fresh())['source'];

    expect($source['deleted'])->toBeTrue()
        ->and($source['snippet'])->toBe('')
        ->and($source['id'])->toBe($message->id);
});

it('draws a system event as nobody rather than as a person', function () {
    [, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Waiting, null);

    $timeline = app(PresentTicket::class)->timeline($ticket->fresh());

    expect($timeline[0]['author'])->toBeNull();
});

it('marks a guest on a ticket', function () {
    [, $guest, , $channel] = ticketFixture();

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    expect(app(PresentTicket::class)->summary($ticket)['opener']['isGuest'])->toBeTrue();
});

it('changes a priority only when it really differs', function () {
    [$member, , , $channel] = ticketFixture();
    $ticket = Ticket::factory()->priority(TicketPriority::Normal)->create(['channel_id' => $channel->id]);

    app(UpdateTicket::class)->priority($ticket, TicketPriority::Normal, $member);
    expect($ticket->events()->count())->toBe(0);

    app(UpdateTicket::class)->priority($ticket, TicketPriority::Urgent, $member);

    expect($ticket->fresh()->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->events()->where('type', TicketEventType::PriorityChanged)->count())->toBe(1);
});
