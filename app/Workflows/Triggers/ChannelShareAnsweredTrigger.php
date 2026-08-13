<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;

/**
 * The workspace we offered a channel to said yes, or no.
 *
 * Runs in the *host* workspace, which is the side that has been waiting. One
 * trigger with a choice rather than two: it is one moment answered two ways,
 * and a workspace that wants to hear about both should not have to write the
 * workflow twice.
 */
class ChannelShareAnsweredTrigger extends ChannelShareTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.channel-share-answered.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.channel-share-answered.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::choice(
                'answer',
                __('workflows.triggers.channel-share-answered.answer.label'),
                [
                    'any' => __('workflows.triggers.channel-share-answered.answer.any'),
                    'accepted' => __('workflows.triggers.channel-share-answered.answer.accepted'),
                    'declined' => __('workflows.triggers.channel-share-answered.answer.declined'),
                ],
                __('workflows.triggers.channel-share-answered.answer.hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::shareProvides(),
            'share.accepted' => __('workflows.provides.share.accepted'),
        ];
    }
}
