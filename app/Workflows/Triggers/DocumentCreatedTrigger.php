<?php

namespace App\Workflows\Triggers;

/**
 * Somebody started a document.
 *
 * What it is reached for: a new document gets its standard opening lines, a
 * link to it goes to the people who are not in the channel, a ticket is opened
 * for whoever has to review it. All three are things somebody does by hand
 * today and forgets on the fourth document.
 */
class DocumentCreatedTrigger extends DocumentTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.document-created.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.document-created.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::documentProvides();
    }
}
