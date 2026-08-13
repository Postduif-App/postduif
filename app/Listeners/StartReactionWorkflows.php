<?php

namespace App\Listeners;

use App\Events\ReactionAdded;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\ReactionTrigger;

/**
 * Set off the workflows that were waiting for a particular emoji.
 *
 * The one trigger a member operates on purpose without any screen for it, which
 * is also what makes it the easiest to set off by accident: reactions come off
 * and go back on, and each putting-on is a run. The depth guard is what keeps a
 * workflow that reacts with the emoji it listens for from going round forever.
 *
 * @extends StartsWorkflows<ReactionAdded>
 */
class StartReactionWorkflows extends StartsWorkflows
{
    public function handle(ReactionAdded $event): void
    {
        $this->start($event);
    }

    protected function trigger(): string
    {
        return ReactionTrigger::class;
    }

    /**
     * @param  ReactionAdded  $event
     */
    protected function workspaceOf(object $event): ?Workspace
    {
        return $event->message->workspace;
    }

    /**
     * @param  ReactionAdded  $event
     * @return array<string, mixed>|null
     */
    protected function contextFor(Workflow $workflow, object $event): ?array
    {
        $message = $event->message;

        if ((string) $workflow->triggerSetting('emoji') !== $event->emoji) {
            return null;
        }

        $channelId = $workflow->triggerSetting('channel_id');

        if (filled($channelId) && (int) $channelId !== $message->channel_id) {
            return null;
        }

        return [
            'emoji' => $event->emoji,
            'message' => ['id' => $message->id, 'text' => $message->body],
            'channel' => ['id' => $message->channel_id, 'name' => $message->channel?->name],

            // The one who reacted, and separately the one who wrote what they
            // reacted to. Telling those apart is most of the point of this
            // trigger — see ReactionTrigger::provides().
            'user' => ['id' => $event->user->id, 'name' => $event->user->name],
            'author' => ['id' => $message->user_id, 'name' => $message->author?->name],
        ];
    }
}
