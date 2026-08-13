<?php

namespace App\Workflows\Triggers;

/**
 * The deadline passed with somebody still to answer.
 *
 * Fired by the nightly prune rather than by the clock, so it arrives when that
 * command runs and not at the stroke of the deadline. Anything asking whether
 * the contract may still be signed has considered it closed since the moment
 * itself.
 *
 * The contract is not gone: it stays around for the grace period, so a workflow
 * may still read who did sign, post about it, or make a fresh copy of it.
 */
class ContractExpiredTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-expired.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-expired.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
        ];
    }
}
