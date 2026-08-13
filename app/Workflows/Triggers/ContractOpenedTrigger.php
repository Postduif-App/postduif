<?php

namespace App\Workflows\Triggers;

/**
 * Somebody looked at their contract for the first time.
 *
 * Once per signer and never again — see the event, and the column behind it.
 *
 * What it is worth is telling two silences apart. A contract nobody has
 * answered may be one that never arrived or one that is being thought about,
 * and only this can say which. "Verstuurd, drie dagen niets, nooit geopend" is
 * a reason to telephone; the other is a reason to wait.
 */
class ContractOpenedTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-opened.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-opened.description');
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
