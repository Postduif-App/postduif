<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody became a member of a channel.
 *
 * The welcome message, in other words, which is what most workspaces reach for
 * this for. Fires on the membership rather than on the account, so somebody who
 * leaves and comes back is welcomed again — which is right: they missed
 * everything in between.
 */
class ChannelJoinTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.channel-join.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.channel-join.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.channel-join.channel.label'),
                __('workflows.triggers.channel-join.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
        ];
    }
}
