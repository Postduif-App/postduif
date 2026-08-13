<?php

namespace App\Workflows\Triggers;

/**
 * One of the signers put their name to it.
 *
 * Fires per signer, the last one included — a contract with three parties fires
 * this three times. Whether this was the last is
 * {{ trigger.signer.is_last }}, and what is left to wait for is
 * {{ trigger.contract.remaining }}; a workflow that wants "iedereen is
 * langs geweest" wants the completed trigger instead, which also waits for the
 * signed copy to exist.
 */
class ContractSignedTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-signed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-signed.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
            ...static::signerProvides(),
        ];
    }
}
