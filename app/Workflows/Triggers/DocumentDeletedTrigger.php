<?php

namespace App\Workflows\Triggers;

/**
 * A document was taken out of a channel.
 *
 * The application says nothing about this by itself, on purpose: a notice that
 * something has been removed only tells people about a document they can no
 * longer read. A workspace that wants a record of it anyway — a line in a log
 * channel, a ticket for whoever keeps the archive — can now write one, which is
 * the whole reason this exists.
 *
 * The document is soft-deleted, so it is still there to be described. What a
 * following step cannot do is send anybody to it.
 */
class DocumentDeletedTrigger extends DocumentTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.document-deleted.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.document-deleted.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::documentProvides();
    }
}
