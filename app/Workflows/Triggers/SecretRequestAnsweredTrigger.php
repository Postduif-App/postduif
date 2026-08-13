<?php

namespace App\Workflows\Triggers;

use App\Features\SecretRequests;
use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody filled in a request for credentials.
 *
 * The handover moment: "de klant heeft de inloggegevens ingevuld" is what a
 * workspace has been asking about twice a week, and it can now open a ticket
 * for itself instead.
 *
 * How many boxes were filled in, and nothing about what went in them. Not a
 * limitation to be lifted later either — the values are encrypted in the
 * browser, and a trigger that could read them would be the feature undone.
 */
class SecretRequestAnsweredTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.secret-request-answered.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.secret-request-answered.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.secret-request-answered.channel.label'),
                __('workflows.triggers.secret-request-answered.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'request.id' => __('workflows.provides.secret.id'),
            'request.title' => __('workflows.provides.secret.title'),
            'request.answered' => __('workflows.provides.secret.answered'),
            'request.outstanding' => __('workflows.provides.secret.outstanding'),
            'request.is_complete' => __('workflows.provides.secret.is_complete'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'requester.id' => __('workflows.provides.secret.requester_id'),
            'requester.name' => __('workflows.provides.secret.requester_name'),
            // Empty when it was filled in by somebody with no account, which is
            // most of them: the whole point is a link you send outside.
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
        ];
    }

    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(SecretRequests::class) ?? false;
    }
}
