<?php

namespace App\Workflows\Triggers;

/**
 * Somebody read the contract and said no.
 *
 * An outcome rather than a failure, and the one that most often has to reach a
 * person quickly: a refusal closes the whole contract, not one part of it, so
 * whatever was waiting on those signatures is now waiting for nothing.
 *
 * The reason they gave, if any, is on the signer's row rather than in the
 * payload — {{ trigger.signer.decline_reason }}.
 */
class ContractDeclinedTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-declined.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-declined.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
            ...static::signerProvides(),
            'signer.decline_reason' => __('workflows.provides.signer.decline_reason'),
        ];
    }
}
