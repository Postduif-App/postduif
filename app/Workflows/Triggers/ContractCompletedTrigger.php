<?php

namespace App\Workflows\Triggers;

/**
 * Everybody has answered and the signed copy is ready.
 *
 * Deliberately later than the last signature. The contract is complete the
 * moment the last answer lands, but the signed PDF is composed on a queue some
 * seconds afterwards — and a workflow that hears "klaar" and immediately posts
 * a download link would post one that leads nowhere. This waits for the
 * document, so {{ trigger.contract.download_url }} works the moment the run
 * starts.
 *
 * "Everybody answered" includes a refusal. A contract that ended in a no is
 * completed too, and {{ trigger.contract.declined_count }} is how a workflow
 * tells the two endings apart.
 */
class ContractCompletedTrigger extends ContractTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.contract-completed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.contract-completed.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::contractProvides(),
            // Only here: until the render has run there is nothing to link to.
            'contract.download_url' => __('workflows.provides.contract.download_url'),
        ];
    }
}
