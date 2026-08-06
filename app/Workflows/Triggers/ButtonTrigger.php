<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowTrigger;

/**
 * Somebody pressed a button in the bar above a channel.
 *
 * The third of the manual triggers, and the one that is put there in advance.
 * The link trigger is reached through a message menu and the slash command
 * through the message field, which both ask somebody to know that the workflow
 * exists. A button is the case where the channel itself remembers: it sits
 * above every conversation in it, labelled, for anybody who walks in.
 *
 * No fields. Where the button goes is decided where buttons are managed — the
 * channel settings — and asking for a channel here as well would be two places
 * saying where one button lives.
 */
class ButtonTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.button.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.button.description');
    }

    /**
     * No message, unlike the link trigger: nothing was pointed at. What there
     * is, is the channel the button hangs above and whoever pressed it.
     *
     * @return array<string, string>
     */
    public static function provides(): array
    {
        return [
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'user.id' => __('workflows.provides.starter.id'),
            'user.name' => __('workflows.provides.starter.name'),
        ];
    }
}
