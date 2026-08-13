<?php

namespace App\Workflows\Triggers;

/**
 * The signed copy could not be composed, and the job gave up.
 *
 * Not news about the agreement — that is signed and unharmed. It is news about
 * us: a finished contract with no document to download, which nobody would
 * otherwise find out about until somebody went looking for the PDF.
 *
 * The trigger for the beheerder's channel, in other words, and the one place in
 * this set where a workflow is a monitoring tool rather than an office
 * assistant.
 */
class ContractRenderFailedTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-render-failed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-render-failed.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
        ];
    }
}
