<?php

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ChannelTicketPolicy;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('opens a ticket from the channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Printer doet het niet',
        'body' => 'Sinds vanochtend een foutcode.',
        'priority' => 'high',
    ])->assertRedirect();

    $ticket = Ticket::sole();

    expect($ticket->title)->toBe('Printer doet het niet')
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->opened_by)->toBe($member->id);
});

it('promotes a message from this channel and refuses one from elsewhere', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $here = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
    ]);

    actingAs($member)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Uit een bericht',
        'body' => 'omschrijving',
        'source_message_id' => $here->id,
    ])->assertRedirect();

    expect(Ticket::sole()->source_message_id)->toBe($here->id);

    $elsewhere = Message::factory()->create();

    actingAs($member)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Uit een ander kanaal',
        'body' => 'omschrijving',
        'source_message_id' => $elsewhere->id,
    ])->assertSessionHasErrors('source_message_id');
});

it('refuses a guest in a members-only channel', function () {
    [, $guest, $workspace, $channel] = ticketFixture(ChannelTicketPolicy::Members);

    actingAs($guest)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Mag niet',
        'body' => 'omschrijving',
    ])->assertForbidden();
});

it('lets a member set status, priority and assignee', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'status' => 'waiting',
        'priority' => 'urgent',
        'assigned_to' => $member->id,
    ])->assertRedirect();

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Waiting)
        ->and($ticket->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->assigned_to)->toBe($member->id);
});

it('lets the customer close and reopen their own ticket but nothing more', function () {
    [, $guest, $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->status(TicketStatus::Resolved)->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    actingAs($guest)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'status' => 'closed',
    ])->assertRedirect();

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);

    actingAs($guest)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'status' => 'waiting',
    ])->assertForbidden();

    actingAs($guest)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'priority' => 'urgent',
    ])->assertForbidden();
});

it('refuses an assignee who is not in the channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    $outsider = User::factory()->create();

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'assigned_to' => $outsider->id,
    ])->assertSessionHasErrors('assigned_to');
});

it('refuses a ticket number from another channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $other = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    $other->members()->attach($member->id, ['joined_at' => now()]);

    $ticket = Ticket::factory()->create(['channel_id' => $other->id]);

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'status' => 'waiting',
    ])->assertNotFound();
});

it('lets everyone in the channel comment, and only the author rewrite it', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    actingAs($guest)->post(route('chat.tickets.comments.store', [$workspace, $channel, $ticket]), [
        'body' => 'het gebeurt nog steeds',
    ])->assertRedirect();

    $comment = TicketComment::sole();

    actingAs($member)->patch(
        route('chat.tickets.comments.update', [$workspace, $channel, $ticket, $comment]),
        ['body' => 'iets anders']
    )->assertForbidden();

    actingAs($guest)->patch(
        route('chat.tickets.comments.update', [$workspace, $channel, $ticket, $comment]),
        ['body' => 'het gebeurt sinds vanochtend']
    )->assertRedirect();

    expect($comment->fresh()->body)->toBe('het gebeurt sinds vanochtend')
        ->and($comment->fresh()->edited_at)->not->toBeNull();
});

it('keeps a withdrawn comment as a tombstone', function () {
    [, $guest, $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);
    $comment = TicketComment::factory()->on($ticket)->by($guest)->create();

    actingAs($guest)->delete(
        route('chat.tickets.comments.destroy', [$workspace, $channel, $ticket, $comment])
    )->assertRedirect();

    expect($comment->fresh()->isDeleted())->toBeTrue();
});

it('hands the board and its counts to the chat page', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    Ticket::factory()->count(2)->create(['channel_id' => $channel->id]);
    Ticket::factory()->status(TicketStatus::Closed)->create(['channel_id' => $channel->id]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.hasTickets', true)
            ->where('channel.canCreateTicket', true)
            ->has('tickets.rows', 3)
            ->where('tickets.counts.open', 2)
            ->where('tickets.counts.closed', 1)
            ->where('ticket', null)
        );
});

it('opens the ticket named in the query string', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'tickets', 'ticket' => $ticket->number]))
        ->assertInertia(fn ($page) => $page
            ->where('ticket.number', $ticket->number)
            ->where('ticket.canManage', true)
            ->has('ticket.timeline')
        );
});

it('says nothing about tickets in a channel that keeps none', function () {
    [$member, , $workspace, $channel] = ticketFixture(ChannelTicketPolicy::Disabled);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.hasTickets', false)
            ->where('tickets', null)
        );
});

it('says in the channel that a ticket was opened and closed', function () {
    [$member, , , $channel] = ticketFixture();

    $ticket = app(CreateTicket::class)
        ->handle($channel, $member, 'Printer kapot', 'omschrijving');

    app(UpdateTicket::class)
        ->status($ticket, TicketStatus::Closed, $member);

    // Ordered explicitly: without it Postgres may hand back the two rows
    // either way round, and the assertion below is about which came first.
    // The id is a ULID, so it sorts by the moment the message was made.
    $announcements = $channel->messages()
        ->whereNotNull('bot_name')
        ->orderBy('id')
        ->pluck('body');

    expect($announcements)->toHaveCount(2)
        ->and($announcements[0])->toContain('Nieuw ticket #1')
        ->and($announcements[1])->toContain('gesloten');
});

it('stays quiet in a channel that switched announcements off', function () {
    [$member, , , $channel] = ticketFixture();
    $channel->update(['ticket_announcements' => false]);

    app(CreateTicket::class)
        ->handle($channel->fresh(), $member, 'Printer kapot', 'omschrijving');

    expect($channel->messages()->whereNotNull('bot_name')->count())->toBe(0);
});

/**
 * The title on its own, which is how the panel sends it now: it is edited in
 * the header where it is read, rather than by opening the description and being
 * asked about that too.
 */
it('corrects only the title, leaving the description alone', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
        'body' => 'De omschrijving blijft staan.',
    ]);

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'title' => 'Alleen de titel',
    ])->assertRedirect();

    $ticket->refresh();

    expect($ticket->title)->toBe('Alleen de titel')
        ->and($ticket->body)->toBe('De omschrijving blijft staan.');
});

it('corrects only the description, leaving the title alone', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
        'title' => 'De titel blijft staan',
    ]);

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'body' => 'Alleen de omschrijving.',
    ])->assertRedirect();

    $ticket->refresh();

    expect($ticket->body)->toBe('Alleen de omschrijving.')
        ->and($ticket->title)->toBe('De titel blijft staan');
});

it('corrects the title and the description of a ticket', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
    ]);

    actingAs($member)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'title' => 'Printer geeft foutcode E5',
        'body' => 'Sinds vanochtend, na het vervangen van de toner.',
    ])->assertRedirect();

    $ticket->refresh();

    expect($ticket->title)->toBe('Printer geeft foutcode E5')
        ->and($ticket->body)->toBe('Sinds vanochtend, na het vervangen van de toner.')
        // Wording is not a move in the handling of the ticket, so the timeline
        // stays clean — see UpdateTicket::describe().
        ->and($ticket->events()->where('type', 'status_changed')->count())->toBe(0);
});

it('refuses a rewrite by a guest who did not raise the ticket', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
    ]);

    actingAs($guest)->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
        'title' => 'Van mij nu',
    ])->assertForbidden();

    expect($ticket->fresh()->title)->not->toBe('Van mij nu');
});

it('deletes a ticket, taking it off the board without touching its comments', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
    ]);
    $comment = TicketComment::factory()->create(['ticket_id' => $ticket->id]);

    actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->delete(route('chat.tickets.destroy', [$workspace, $channel, $ticket]))
        ->assertRedirect(route('chat.show', [$workspace, $channel]));

    expect(Ticket::whereKey($ticket->id)->exists())->toBeFalse()
        ->and(Ticket::withTrashed()->whereKey($ticket->id)->exists())->toBeTrue()
        // Withdrawing a comment means something else on a ticket — a tombstone
        // in the timeline — so a deleted ticket must not withdraw all of them.
        ->and($comment->fresh()->deleted_at)->toBeNull();
});

it('refuses a delete by the guest who raised the ticket', function () {
    [, $guest, $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    actingAs($guest)
        ->delete(route('chat.tickets.destroy', [$workspace, $channel, $ticket]))
        ->assertForbidden();

    expect(Ticket::whereKey($ticket->id)->exists())->toBeTrue();
});

it('refuses a delete of a ticket from another channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $elsewhere = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $ticket = Ticket::factory()->create([
        'channel_id' => $elsewhere->id,
        'opened_by' => $member->id,
    ]);

    actingAs($member)
        ->delete(route('chat.tickets.destroy', [$workspace, $channel, $ticket]))
        ->assertNotFound();

    expect(Ticket::whereKey($ticket->id)->exists())->toBeTrue();
});

it('drops a deleted ticket from the board it was open on', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
    ]);

    actingAs($member)->delete(route('chat.tickets.destroy', [$workspace, $channel, $ticket]));

    // The ?ticket= that is still in the URL now resolves to nothing, which is
    // what closes the panel — see TicketController::destroy().
    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'tickets', 'ticket' => $ticket->number]))
        ->assertInertia(fn ($page) => $page
            ->where('ticket', null)
            ->where('tickets.rows', []));
});
