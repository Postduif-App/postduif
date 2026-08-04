<?php

namespace App\Listeners;

use App\Actions\Workflows\StartWorkflow;
use App\Events\ReactionAdded;
use App\Models\Workflow;
use App\Workflows\Triggers\ReactionTrigger;

/**
 * Set off the workflows that were waiting for a particular emoji.
 *
 * The one trigger a member operates on purpose without any screen for it, which
 * is also what makes it the easiest to set off by accident: reactions come off
 * and go back on, and each putting-on is a run. The depth guard is what keeps a
 * workflow that reacts with the emoji it listens for from going round forever.
 */
class StartReactionWorkflows
{
    public function __construct(private readonly StartWorkflow $startWorkflow) {}

    public function handle(ReactionAdded $event): void
    {
        $message = $event->message;

        $workflows = Workflow::query()
            ->listeningFor($message->workspace, ReactionTrigger::key())
            ->get();

        foreach ($workflows as $workflow) {
            if (! $this->matches($workflow, $event)) {
                continue;
            }

            $this->startWorkflow->handle($workflow, [
                'emoji' => $event->emoji,
                'message' => ['id' => $message->id, 'text' => $message->body],
                'channel' => ['id' => $message->channel_id, 'name' => $message->channel?->name],

                // The one who reacted, and separately the one who wrote what
                // they reacted to. Telling those apart is most of the point of
                // this trigger — see ReactionTrigger::provides().
                'user' => ['id' => $event->user->id, 'name' => $event->user->name],
                'author' => ['id' => $message->user_id, 'name' => $message->author?->name],
            ]);
        }
    }

    private function matches(Workflow $workflow, ReactionAdded $event): bool
    {
        if ((string) $workflow->triggerSetting('emoji') !== $event->emoji) {
            return false;
        }

        $channelId = $workflow->triggerSetting('channel_id');

        return blank($channelId) || (int) $channelId === $event->message->channel_id;
    }
}
