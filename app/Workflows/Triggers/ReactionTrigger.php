<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody put a particular emoji on a message.
 *
 * The one trigger a member operates deliberately without any screen for it: an
 * emoji is a button everybody already knows how to press. That is also what
 * makes it worth being careful with — reactions come off again, and this fires
 * on the putting on, not on the taking off, so a workflow behind one runs as
 * often as the reaction is re-applied.
 */
class ReactionTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.reaction.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.reaction.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::emoji(
                'emoji',
                __('workflows.triggers.reaction.emoji.label'),
                __('workflows.triggers.reaction.emoji.hint'),
            ),
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.reaction.channel.label'),
                __('workflows.triggers.reaction.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'emoji' => __('workflows.provides.emoji'),
            'message.id' => __('workflows.provides.message.id'),
            'message.text' => __('workflows.provides.message.text'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),

            /*
             * Two people, and telling them apart is the whole difficulty of
             * this trigger: the one who reacted, and the one who wrote what
             * they reacted to. A workflow that thanks the wrong one is the
             * mistake this pair of names exists to prevent.
             */
            'user.id' => __('workflows.provides.reactor.id'),
            'user.name' => __('workflows.provides.reactor.name'),
            'author.id' => __('workflows.provides.author.id'),
            'author.name' => __('workflows.provides.author.name'),
        ];
    }
}
