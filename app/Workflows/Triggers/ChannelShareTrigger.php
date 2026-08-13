<?php

namespace App\Workflows\Triggers;

use App\Features\SharedChannels;
use App\Models\Workflow;
use App\Workflows\WorkflowTrigger;

/**
 * What the three shared-channel triggers share, and the question they all had
 * to answer first: which of the two workspaces does this happen in.
 *
 * A share joins a host and a guest, and both have workflows. Firing in both
 * would mean every workspace hearing about its own actions, which is noise
 * dressed up as thoroughness. So each trigger belongs to whichever side is
 * being *told* something rather than doing it: the guest hears the offer and
 * the withdrawal, the host hears the answer. See StartChannelShareWorkflows,
 * where that choice is one line per trigger.
 *
 * No channel filter, unlike every other trigger here. The channel being offered
 * belongs to the other workspace, so a guest's picker could not list it — and
 * on the host's side there is exactly one channel in question anyway.
 */
abstract class ChannelShareTrigger extends WorkflowTrigger
{
    /** @return array<string, string> */
    protected static function shareProvides(): array
    {
        return [
            'share.id' => __('workflows.provides.share.id'),
            'share.can_post' => __('workflows.provides.share.can_post'),
            'channel.id' => __('workflows.provides.share.channel_id'),
            'channel.name' => __('workflows.provides.share.channel_name'),
            'host.id' => __('workflows.provides.share.host_id'),
            'host.name' => __('workflows.provides.share.host_name'),
            'guest.id' => __('workflows.provides.share.guest_id'),
            'guest.name' => __('workflows.provides.share.guest_name'),
        ];
    }

    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(SharedChannels::class) ?? false;
    }
}
