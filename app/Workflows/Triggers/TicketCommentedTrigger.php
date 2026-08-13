<?php

namespace App\Workflows\Triggers;

/**
 * Somebody said something on a ticket.
 *
 * Apart from the changed trigger because a comment is not a change: it has
 * words and an author and nothing it moved from. The timeline keeps the two
 * apart for a related reason — a comment can be edited and withdrawn, an event
 * is what happened and stays.
 *
 * The path worth knowing about is comment.is_first_response. "De klant kreeg
 * eindelijk antwoord" is the one comment in a thread that means something to
 * anybody outside it, and nothing downstream can work it out afterwards.
 */
class TicketCommentedTrigger extends TicketTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.ticket-commented.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.ticket-commented.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::ticketProvides(),
            'comment.id' => __('workflows.provides.ticket.comment_id'),
            'comment.body' => __('workflows.provides.ticket.comment_body'),
            'comment.is_first_response' => __('workflows.provides.ticket.comment_first'),
            'author.id' => __('workflows.provides.ticket.comment_author_id'),
            'author.name' => __('workflows.provides.ticket.comment_author_name'),
        ];
    }
}
