<?php

namespace App\Listeners;

use App\Events\ChannelMemberJoined;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\ChannelJoinTrigger;

/**
 * Set off the workflows that were waiting for somebody to arrive.
 *
 * The welcome message, in practice. Fires on the membership rather than on the
 * account, so somebody who left and came back is welcomed again — which is
 * right: they missed everything in between.
 *
 * @extends StartsWorkflows<ChannelMemberJoined>
 */
class StartChannelJoinWorkflows extends StartsWorkflows
{
    public function handle(ChannelMemberJoined $event): void
    {
        $this->start($event);
    }

    protected function trigger(): string
    {
        return ChannelJoinTrigger::class;
    }

    /**
     * loadMissing: the event carries whatever channel the joining code had in
     * its hands, which is rarely one with its workspace loaded.
     *
     * @param  ChannelMemberJoined  $event
     */
    protected function workspaceOf(object $event): ?Workspace
    {
        return $event->channel->loadMissing('workspace')->workspace;
    }

    /**
     * @param  ChannelMemberJoined  $event
     * @return array<string, mixed>|null
     */
    protected function contextFor(Workflow $workflow, object $event): ?array
    {
        $channel = $event->channel;
        $channelId = $workflow->triggerSetting('channel_id');

        if (filled($channelId) && (int) $channelId !== $channel->id) {
            return null;
        }

        return [
            'channel' => ['id' => $channel->id, 'name' => $channel->name],
            'user' => ['id' => $event->user->id, 'name' => $event->user->name],
        ];
    }
}
