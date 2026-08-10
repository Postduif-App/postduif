<?php

namespace App\Listeners;

use App\Actions\Workflows\StartWorkflow;
use App\Events\ChannelMemberJoined;
use App\Models\Workflow;
use App\Workflows\Triggers\ChannelJoinTrigger;

/**
 * Set off the workflows that were waiting for somebody to arrive.
 *
 * The welcome message, in practice. Fires on the membership rather than on the
 * account, so somebody who left and came back is welcomed again — which is
 * right: they missed everything in between.
 */
class StartChannelJoinWorkflows
{
    public function __construct(private readonly StartWorkflow $startWorkflow) {}

    public function handle(ChannelMemberJoined $event): void
    {
        $channel = $event->channel;

        // loadMissing: the event carries whatever channel the joining code had
        // in its hands, which is rarely one with its workspace loaded.
        $workflows = Workflow::query()
            ->listeningFor($channel->loadMissing('workspace')->workspace, ChannelJoinTrigger::key())
            ->get();

        foreach ($workflows as $workflow) {
            $channelId = $workflow->triggerSetting('channel_id');

            if (filled($channelId) && (int) $channelId !== $channel->id) {
                continue;
            }

            $this->startWorkflow->handle($workflow, [
                'channel' => ['id' => $channel->id, 'name' => $channel->name],
                'user' => ['id' => $event->user->id, 'name' => $event->user->name],
            ]);
        }
    }
}
