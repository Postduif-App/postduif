<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody said one of these words.
 *
 * The busiest trigger there is: every message in the workspace is a candidate,
 * which is why the words and the channel are matched before a run is created
 * rather than after — see the listener.
 */
class MessageKeywordTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.message-keyword.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.message-keyword.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::words(
                'keywords',
                __('workflows.triggers.message-keyword.keywords.label'),
                __('workflows.triggers.message-keyword.keywords.hint'),
            ),
            /*
             * Optional, and "everywhere" is what leaving it empty means. That
             * is the wider of the two and therefore the one worth being able to
             * say out loud — a required channel would make "watch the whole
             * workspace for the word storing" impossible to express.
             */
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.message-keyword.channel.label'),
                __('workflows.triggers.message-keyword.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'message.id' => __('workflows.provides.message.id'),
            'message.text' => __('workflows.provides.message.text'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
            'keyword' => __('workflows.provides.keyword'),
        ];
    }
}
