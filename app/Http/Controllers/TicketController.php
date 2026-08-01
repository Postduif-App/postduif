<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Tickets are read through the chat page rather than through a page of their
 * own: the board is a second view of a channel, and it needs the same sidebar,
 * the same unread counts and the same live connection. What lives here is
 * everything that changes a ticket.
 *
 * Which ticket is open travels in the query string, exactly like an open thread
 * does — so a ticket is linkable and survives a refresh.
 */
class TicketController extends Controller
{
    public function store(
        StoreTicketRequest $request,
        Workspace $workspace,
        Channel $channel,
        CreateTicket $createTicket,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $ticket = $createTicket->handle(
            channel: $channel,
            opener: $request->user(),
            title: $request->string('title')->trim()->value(),
            body: $request->string('body')->trim()->value(),
            priority: $this->priority($request->input('priority')),
            source: $this->source($channel, $request->input('source_message_id')),
        );

        return redirect()->route('chat.show', [
            $workspace,
            $channel,
            'view' => 'tickets',
            'ticket' => $ticket->number,
        ]);
    }

    /**
     * Change one or more fields of a ticket.
     *
     * Each field is authorised on its own. A customer may say their ticket is
     * done, and may not decide it is urgent; refusing the whole request over the
     * second would also swallow the first.
     */
    public function update(
        UpdateTicketRequest $request,
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        UpdateTicket $updateTicket,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $user = $request->user();

        if ($request->has('status')) {
            $status = TicketStatus::from($request->string('status')->value());

            $this->authorizeStatus($user, $ticket, $status);
            $updateTicket->status($ticket, $status, $user);
        }

        if ($request->has('priority')) {
            $this->authorize('manage', $ticket);
            $updateTicket->priority($ticket, TicketPriority::from($request->string('priority')->value()), $user);
        }

        if ($request->has('assigned_to')) {
            $this->authorize('manage', $ticket);
            // Narrowed by hand: the id arrives as whatever the browser sent,
            // and null is a real value here — it is how a ticket is unassigned.
            $assigneeId = $request->input('assigned_to');

            $updateTicket->assign(
                $ticket,
                $assigneeId === null ? null : User::whereKey($assigneeId)->first(),
                $user,
            );
        }

        if ($request->has('due_at')) {
            $this->authorize('manage', $ticket);
            $updateTicket->due($ticket, $request->date('due_at'), $user);
        }

        if ($request->hasAny(['title', 'body'])) {
            $this->authorize('update', $ticket);
            $updateTicket->describe(
                $ticket,
                $request->string('title', $ticket->title)->trim()->value(),
                $request->string('body', $ticket->body)->trim()->value(),
            );
        }

        return back();
    }

    /**
     * Who may move a ticket to a given status.
     *
     * Anyone who manages the channel's tickets may move it anywhere. Whoever
     * raised it gets two moves and no more: closing it, and putting it back to
     * open because it was not fixed after all. Those are the two things only
     * they can know.
     */
    private function authorizeStatus(User $user, Ticket $ticket, TicketStatus $status): void
    {
        if ($user->can('manage', $ticket)) {
            return;
        }

        abort_unless(
            $user->can('confirm', $ticket)
                && in_array($status, [TicketStatus::Closed, TicketStatus::Open], strict: true),
            403,
        );
    }

    private function priority(?string $value): TicketPriority
    {
        return $value === null ? TicketPriority::Normal : TicketPriority::from($value);
    }

    /**
     * The message a ticket is being promoted out of.
     *
     * withTrashed on purpose: promoting a message somebody deleted a second ago
     * is a race, not an attack, and the ticket records where it came from either
     * way.
     */
    private function source(Channel $channel, ?string $messageId): ?Message
    {
        if ($messageId === null) {
            return null;
        }

        return $channel->messages()->withTrashed()->whereKey($messageId)->first();
    }
}
