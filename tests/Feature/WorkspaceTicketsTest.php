<?php

use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lists tickets from every channel the member can see', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $second = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    $second->members()->attach($member->id, ['joined_at' => now()]);

    Ticket::factory()->create(['channel_id' => $channel->id, 'title' => 'Printer']);
    Ticket::factory()->create(['channel_id' => $second->id, 'title' => 'Beamer']);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->component('chat/tickets')
            ->has('rows', 2)
        );
});

it('leaves out tickets from a channel the member is not in', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => 'private',
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    Ticket::factory()->create(['channel_id' => $channel->id]);
    Ticket::factory()->create(['channel_id' => $private->id, 'title' => 'Geheim']);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('rows', 1));
});

it('shows a guest only the channels they were put in', function () {
    [, $guest, $workspace, $channel] = ticketFixture();

    // A public channel the guest was left out of does not exist for them —
    // scopeVisibleTo's rule, which this page leans on rather than restating.
    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    Ticket::factory()->create(['channel_id' => $channel->id]);
    Ticket::factory()->create(['channel_id' => $elsewhere->id]);

    actingAs($guest)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('rows', 1));
});

it('shows only outstanding work by default', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::Open]);
    Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::Closed]);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('rows', 1));

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'open' => '0']))
        ->assertInertia(fn ($page) => $page->has('rows', 2));
});

it('filters by status, priority and channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $second = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    $second->members()->attach($member->id, ['joined_at' => now()]);

    Ticket::factory()->create([
        'channel_id' => $channel->id,
        'status' => TicketStatus::Waiting,
        'priority' => TicketPriority::High,
    ]);
    Ticket::factory()->create([
        'channel_id' => $second->id,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'status' => 'waiting']))
        ->assertInertia(fn ($page) => $page->has('rows', 1));

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'priority' => 'high']))
        ->assertInertia(fn ($page) => $page->has('rows', 1));

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'channel' => $second->id]))
        ->assertInertia(fn ($page) => $page->has('rows', 1));
});

it('ignores a filter value that means nothing', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::Open]);

    // A kept link is not an error page.
    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'status' => 'verzonnen']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('filters.status', null)
        );
});

it('names the channel each ticket came from', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    Ticket::factory()->create(['channel_id' => $channel->id]);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->where('rows.0.channelLabel', $channel->name));
});

it('counts every status, not just the filtered one', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::Open]);
    Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::Closed]);

    // The counts are the filter buttons; applying the filter to them first
    // would make every button but the current one read zero.
    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'status' => 'open']))
        ->assertInertia(fn ($page) => $page
            ->where('counts.open', 1)
            ->where('counts.closed', 1)
        );
});

it('says nothing at all to somebody outside the workspace', function () {
    [, , $workspace] = ticketFixture();
    $outsider = User::factory()->create();

    actingAs($outsider)
        ->get(route('chat.tickets.index', $workspace))
        ->assertForbidden();
});

it('leaves out a channel that keeps no tickets', function () {
    [$member, , $workspace] = ticketFixture(ChannelTicketPolicy::Disabled);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('ticketChannels', 0));
});

it('carries the same sidebar as a channel page', function () {
    [$member, , $workspace] = ticketFixture();

    $workspace->members()->attach(User::factory()->create()->id, [
        'workspace_role_id' => roleId($workspace, SystemRole::Member),
        'joined_at' => now(),
    ]);

    actingAs($member)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('channels')
            ->has('directMessages')
            ->has('activeThreads')
            ->where('workspace.slug', $workspace->slug)
        );
});

it('offers the sidebar entry only once a channel keeps tickets', function () {
    [$member, , $workspace, $channel] = ticketFixture(ChannelTicketPolicy::Disabled);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channels.0.hasTickets', false));

    $channel->update(['ticket_policy' => ChannelTicketPolicy::Everyone]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channels.0.hasTickets', true));
});

it('opens the ticket named in the query string, with what the panel needs', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'ticket' => $ticket->number]))
        ->assertInertia(fn ($page) => $page
            ->where('ticket.number', $ticket->number)
            ->where('ticket.channelId', $channel->id)
            ->where('ticket.canManage', true)
            // The assignee picker needs the members of the channel the ticket
            // lives in; this page has no channel of its own to take them from.
            ->has('ticket.channelMembers', 2)
            ->has('ticket.timeline')
        );
});

it('refuses to open a ticket from a channel the member cannot see', function () {
    [$member, , $workspace] = ticketFixture();

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => 'private',
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    $hidden = Ticket::factory()->create(['channel_id' => $private->id]);

    actingAs($member)
        ->get(route('chat.tickets.index', [$workspace, 'ticket' => $hidden->number]))
        ->assertInertia(fn ($page) => $page->where('ticket', null));
});

it('authorises each ticket on its own, not the page', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();

    $theirs = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    // A customer may say their own ticket is done and may not decide it is
    // urgent — the same split the channel panel makes.
    actingAs($guest)
        ->get(route('chat.tickets.index', [$workspace, 'ticket' => $theirs->number]))
        ->assertInertia(fn ($page) => $page
            ->where('ticket.canManage', false)
            ->where('ticket.canConfirm', true)
        );
});

it('changes a ticket from the global list and comes back to it', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'status' => TicketStatus::Open,
    ]);

    $from = route('chat.tickets.index', [$workspace, 'ticket' => $ticket->number]);

    actingAs($member)
        ->from($from)
        ->patch(route('chat.tickets.update', [$workspace, $channel, $ticket]), [
            'status' => 'in_progress',
        ])
        ->assertRedirect($from);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

it('offers only the channels this member may open a ticket in', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    // Keeps tickets, but this member never joined it — reading along is not
    // enough to add work to it.
    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    // Keyed by id rather than by position: the list is sorted by channel name,
    // which the factory makes up.
    $offered = collect(
        actingAs($member)
            ->get(route('chat.tickets.index', $workspace))
            ->viewData('page')['props']['ticketChannels']
    )->keyBy('id');

    // Both are filterable, because both are readable — but only one can be
    // filed in.
    expect($offered)->toHaveCount(2)
        ->and($offered[$channel->id]['canCreate'])->toBeTrue()
        ->and($offered[$elsewhere->id]['canCreate'])->toBeFalse();
});

it('offers a guest nothing in a members-only ticket channel', function () {
    [, $guest, $workspace] = ticketFixture(ChannelTicketPolicy::Members);

    // They read the tickets there and cannot raise one, which is what the
    // policy says — so the dialog has nothing to offer them.
    actingAs($guest)
        ->get(route('chat.tickets.index', $workspace))
        ->assertInertia(fn ($page) => $page->where('ticketChannels.0.canCreate', false));
});

it('opens a ticket in the channel that was picked', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Beamer doet het niet',
        'body' => 'Sinds vanochtend geen beeld.',
    ])->assertRedirect();

    expect(Ticket::sole()->channel_id)->toBe($channel->id);
});

it('refuses a ticket in a channel the member never joined', function () {
    [$member, , $workspace] = ticketFixture();

    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    // The dialog leaves this channel out; the endpoint refuses it regardless.
    actingAs($member)->post(route('chat.tickets.store', [$workspace, $elsewhere]), [
        'title' => 'Van buitenaf',
        'body' => 'Zou niet moeten kunnen.',
    ])->assertForbidden();

    expect(Ticket::count())->toBe(0);
});
