<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowTrigger;

/**
 * Somebody set it off themselves, from a message.
 *
 * The manual one. In Slack it is called "from a link", which describes how it
 * used to be built rather than what it is: in practice it is an entry in the
 * message menu, next to forwarding and pinning.
 *
 * No fields at all. What it needs to know — which message, which channel — is
 * whatever the person had in front of them when they chose it, and asking them
 * to pick a channel here would be asking about the one thing they cannot get
 * wrong.
 */
class LinkTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.link.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.link.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'message.id' => __('workflows.provides.message.id'),
            'message.text' => __('workflows.provides.message.text'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'user.id' => __('workflows.provides.starter.id'),
            'user.name' => __('workflows.provides.starter.name'),
            'author.id' => __('workflows.provides.author.id'),
            'author.name' => __('workflows.provides.author.name'),
        ];
    }
}
