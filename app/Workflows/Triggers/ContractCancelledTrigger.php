<?php

namespace App\Workflows\Triggers;

/**
 * Whoever asked for the signatures stopped the contract.
 *
 * Fires only for a cancel that actually took: a stop arriving at the same
 * moment as the last signature loses, and the workflow hears nothing.
 *
 * The links are not dead, which matters for what a workflow should say here.
 * Somebody following theirs is told the contract was withdrawn — see
 * CancelContract — so a message announcing this does not have to warn anybody
 * about a broken link.
 */
class ContractCancelledTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-cancelled.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-cancelled.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
        ];
    }
}
