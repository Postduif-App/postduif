<?php

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

use function Pest\Laravel\actingAs;

/**
 * A channel that keeps tickets, plus one ticket in it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Ticket}
 */
function announcingTicket(bool $statusAnnouncements = true, bool $announcements = true): array
{
    [$member, , $workspace, $channel] = ticketFixture();

    $channel->update([
        'ticket_announcements' => $announcements,
        'ticket_status_announcements' => $statusAnnouncements,
    ]);

    $ticket = app(CreateTicket::class)->handle(
        channel: $channel,
        opener: $member,
        title: 'Printer doet het niet',
        body: 'Sinds vanochtend een foutcode.',
    );

    return [$member, $workspace, $channel, $ticket];
}

/**
 * Everything the bot said in this channel, oldest first.
 *
 * @return Collection<int, string>
 */
function botMessages(Channel $channel): Collection
{
    return Message::query()
        ->where('channel_id', $channel->id)
        ->whereNotNull('bot_name')
        ->orderBy('id')
        ->pluck('body');
}

it('says in the channel that a ticket moved to another status', function () {
    [$member, , $channel, $ticket] = announcingTicket();

    app(UpdateTicket::class)->status($ticket, TicketStatus::InProgress, $member);

    expect(botMessages($channel)->last())
        ->toContain("#{$ticket->number}")
        ->toContain('Open → In behandeling');
});

it('stays quiet about status changes unless the channel asked for them', function () {
    [$member, , $channel, $ticket] = announcingTicket(statusAnnouncements: false);

    app(UpdateTicket::class)->status($ticket, TicketStatus::InProgress, $member);

    // The opening announcement is the only thing said: that one is on by
    // default, the stream of moves in between is not.
    expect(botMessages($channel))->toHaveCount(1);
});

it('says nothing at all when the channel announces no tickets', function () {
    [$member, , $channel, $ticket] = announcingTicket(announcements: false);

    app(UpdateTicket::class)->status($ticket, TicketStatus::InProgress, $member);

    expect(botMessages($channel))->toBeEmpty();
});

it('announces a closed ticket once, not twice', function () {
    [$member, , $channel, $ticket] = announcingTicket();

    app(UpdateTicket::class)->status($ticket, TicketStatus::Closed, $member);

    $closing = botMessages($channel)->filter(
        fn (string $body): bool => str_contains($body, 'gesloten'),
    );

    expect($closing)->toHaveCount(1);
});

it('saves the status announcement setting from the channel dialog', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'everyone',
        'ticket_status_announcements' => true,
    ])->assertRedirect();

    expect($channel->fresh()->ticket_status_announcements)->toBeTrue();
});
