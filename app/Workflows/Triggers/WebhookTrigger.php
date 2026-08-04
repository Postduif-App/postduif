<?php

namespace App\Workflows\Triggers;

use App\Features\Webhooks;
use App\Models\Workflow;
use App\Workflows\WorkflowTrigger;

/**
 * Something outside called a URL of ours.
 *
 * No fields: the URL is minted when the workflow is saved, and there is nothing
 * to configure about waiting for a request. What arrives lands whole under
 * payload, because there is no telling in advance what a sender will send —
 * which is why the last body is kept, so somebody can read it while writing the
 * steps.
 */
class WebhookTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.webhook.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.webhook.description');
    }

    /**
     * The one trigger that answers to a second switch.
     *
     * A workspace that has closed the door on webhooks has said that nothing
     * outside may reach in and post; a workflow trigger with a URL is that same
     * door with a different sign on it. Switching workflows on is not a way to
     * reopen it.
     */
    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(Webhooks::class) ?? false;
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            /*
             * Named as one thing rather than as a list of paths, because there
             * is no list to give: the shape is the sender's, not ours. The
             * builder fills this out from the last body that actually arrived —
             * see the webhook trigger task — which is the only honest source
             * for it.
             */
            'payload' => __('workflows.provides.payload'),
        ];
    }
}
