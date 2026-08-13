<?php

namespace App\Workflows\Triggers;

use App\Features\Tickets;
use App\Models\Workspace;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * What the ticket triggers have in common.
 *
 * Four of them where contracts have eight, and the difference is worth writing
 * down because the two slices were judged by the same rule. A contract's eight
 * moments carry genuinely different cargo — a refusal has a reason, a
 * completion has a document, a send has neither — so eight promises about
 * variables was the only honest way to make them.
 *
 * Everything that happens to a ticket carries exactly the same thing: the
 * ticket, whoever did it, and what changed from what. So the four here are cut
 * by payload rather than by event: created (nothing changed from anything),
 * changed (the from-and-to family, with a dropdown for which), commented (words
 * and an author), and left sitting (a reason). Splitting "status gewijzigd"
 * from "prioriteit gewijzigd" would be two identical promises with two labels.
 *
 * The channel filter is on all four. A workspace's tickets are not one queue —
 * the customer channel and the internal board want different workflows — and
 * without it every ticket workflow would fire on every ticket anywhere.
 */
abstract class TicketTrigger extends WorkflowTrigger
{
    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.ticket.channel.label'),
                __('workflows.triggers.ticket.channel.hint'),
                required: false,
            ),
        ];
    }

    /**
     * Everything about the ticket itself.
     *
     * hours_open, is_overdue and has_assignee are worked out where the trigger
     * data is built, for the reason the contract triggers give: a condition can
     * compare a number but cannot produce one. "Langer dan een dag open" is a
     * condition only because something offers the hours.
     *
     * @return array<string, string>
     */
    protected static function ticketProvides(): array
    {
        return [
            'ticket.id' => __('workflows.provides.ticket.id'),
            'ticket.number' => __('workflows.provides.ticket.number'),
            'ticket.title' => __('workflows.provides.ticket.title'),
            'ticket.body' => __('workflows.provides.ticket.body'),
            'ticket.status' => __('workflows.provides.ticket.status'),
            'ticket.priority' => __('workflows.provides.ticket.priority'),
            'ticket.due_at' => __('workflows.provides.ticket.due_at'),
            'ticket.hours_open' => __('workflows.provides.ticket.hours_open'),
            'ticket.is_overdue' => __('workflows.provides.ticket.is_overdue'),
            'ticket.has_assignee' => __('workflows.provides.ticket.has_assignee'),
            'ticket.answered' => __('workflows.provides.ticket.answered'),
            'assignee.id' => __('workflows.provides.ticket.assignee_id'),
            'assignee.name' => __('workflows.provides.ticket.assignee_name'),
            'reporter.id' => __('workflows.provides.ticket.reporter_id'),
            'reporter.name' => __('workflows.provides.ticket.reporter_name'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
        ];
    }

    /**
     * And whoever did it, where there is one.
     *
     * Empty for the scheduler and for mail that came in from outside, which is
     * the honest answer rather than a name to fall back on — a workflow saying
     * "gewijzigd door " visibly wants filling in, where "gewijzigd door
     * Postduif" invites somebody to go and look for an account that does not
     * exist.
     *
     * @return array<string, string>
     */
    protected static function actorProvides(): array
    {
        return [
            'actor.id' => __('workflows.provides.ticket.actor_id'),
            'actor.name' => __('workflows.provides.ticket.actor_name'),
        ];
    }

    public static function availableFor(Workspace $workspace): bool
    {
        return $workspace->hasFeature(Tickets::class);
    }
}
