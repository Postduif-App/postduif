<?php

use App\Actions\Tickets\CreateTicket;
use App\Enums\ChannelTicketPolicy;
use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\WorkspaceRole;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

it('opens a ticket and writes down that it happened', function () {
    [$member, , , $channel] = ticketFixture();

    $ticket = app(CreateTicket::class)->handle(
        channel: $channel,
        opener: $member,
        title: 'Printer doet het niet',
        body: 'Sinds vanochtend geeft hij een foutcode.',
        priority: TicketPriority::High,
    );

    expect($ticket->number)->toBe(1)
        ->and($ticket->workspace_id)->toBe($channel->workspace_id)
        ->and($ticket->status->isOpen())->toBeTrue()
        ->and($ticket->events()->first()->type)->toBe(TicketEventType::Created);
});

it('keeps the message it was promoted out of as provenance, not as content', function () {
    [$member, , , $channel] = ticketFixture();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'body' => 'de printer doet het niet',
    ]);

    $ticket = app(CreateTicket::class)->handle(
        channel: $channel,
        opener: $member,
        title: 'Printer doet het niet',
        body: 'Uitgebreidere omschrijving.',
        source: $message,
    );

    $message->update(['body' => 'laat maar, opgelost']);

    expect($ticket->fresh()->body)->toBe('Uitgebreidere omschrijving.')
        ->and($ticket->source_message_id)->toBe($message->id);
});

it('does not hand out a number to a ticket that never gets stored', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    try {
        DB::transaction(function () use ($channel, $member) {
            app(CreateTicket::class)->handle($channel, $member, 'Weg hiermee', 'x');

            throw new RuntimeException('afgebroken');
        });
    } catch (RuntimeException) {
        // Expected: the point is what the counter looks like afterwards.
    }

    expect($workspace->fresh()->next_ticket_number)->toBe(1)
        ->and(Ticket::count())->toBe(0);
});

it('lets a guest open a ticket in a customer channel', function () {
    [, $guest, , $channel] = ticketFixture();

    expect($guest->can('create', [Ticket::class, $channel]))->toBeTrue();
});

it('keeps a guest out when the channel is members only', function () {
    [$member, $guest, , $channel] = ticketFixture(ChannelTicketPolicy::Members);

    expect($guest->can('create', [Ticket::class, $channel]))->toBeFalse()
        ->and($member->can('create', [Ticket::class, $channel]))->toBeTrue();
});

it('has no tickets at all when the channel does not keep them', function () {
    [$member, , , $channel] = ticketFixture(ChannelTicketPolicy::Disabled);

    expect($member->can('create', [Ticket::class, $channel]))->toBeFalse()
        ->and($member->can('viewBoard', [Ticket::class, $channel]))->toBeFalse();
});

it('lets a guest comment and confirm, but not manage', function () {
    [, $guest, , $channel] = ticketFixture();

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    expect($guest->can('view', $ticket))->toBeTrue()
        ->and($guest->can('comment', $ticket))->toBeTrue()
        ->and($guest->can('confirm', $ticket))->toBeTrue()
        ->and($guest->can('manage', $ticket))->toBeFalse();
});

it('keeps somebody outside the channel away from its tickets', function () {
    [, , $workspace, $channel] = ticketFixture();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, [
        'role' => WorkspaceRole::Guest->value,
        'joined_at' => now(),
    ]);

    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    expect($outsider->can('view', $ticket))->toBeFalse();
});

it('saves the ticket settings of a channel', function () {
    [$member, , $workspace, $channel] = ticketFixture(ChannelTicketPolicy::Disabled);

    actingAs($member)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'everyone',
        'ticket_policy' => 'everyone',
        'ticket_announcements' => false,
    ])->assertRedirect();

    $channel->refresh();

    expect($channel->ticket_policy)->toBe(ChannelTicketPolicy::Everyone)
        ->and($channel->ticket_announcements)->toBeFalse();
});

it('leaves the ticket settings alone when a request says nothing about them', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)->patch(route('chat.channels.update', [$workspace, $channel]), [
        'posting_policy' => 'admins',
    ])->assertRedirect();

    expect($channel->fresh()->ticket_policy)->toBe(ChannelTicketPolicy::Everyone);
});
