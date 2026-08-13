<?php

namespace App\Workflows\Triggers;

/**
 * A contract went out to be signed.
 *
 * The start of the story, and the trigger most workflows about contracts hang
 * their first step on: note it in a channel, open a ticket for the follow-up,
 * write the deadline somewhere people look.
 *
 * Fired once per contract, after the invitations have gone to the mailer. A
 * template is never sent, so this never fires for one.
 */
class ContractSentTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-sent.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-sent.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
        ];
    }
}
