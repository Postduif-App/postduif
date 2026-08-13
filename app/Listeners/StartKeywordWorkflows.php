<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\MessageKeywordTrigger;

/**
 * Set off the workflows that were waiting for one of these words.
 *
 * The busiest listener in the application: it runs for every message posted
 * anywhere. Two things keep that cheap. The query is the one index on
 * (workspace_id, trigger_type, enabled_at), and in a workspace with no keyword
 * workflows it comes back empty and nothing else happens. The matching is done
 * here rather than in a run, because creating a row for every message in order
 * to throw it away is a table that fills up with nothing.
 *
 * @extends StartsWorkflows<MessageSent>
 */
class StartKeywordWorkflows extends StartsWorkflows
{
    public function handle(MessageSent $event): void
    {
        $this->start($event);
    }

    protected function trigger(): string
    {
        return MessageKeywordTrigger::class;
    }

    /**
     * @param  MessageSent  $event
     */
    protected function workspaceOf(object $event): ?Workspace
    {
        return $event->message->workspace;
    }

    /**
     * @param  MessageSent  $event
     * @return array<string, mixed>|null
     */
    protected function contextFor(Workflow $workflow, object $event): ?array
    {
        $message = $event->message;
        $keyword = $this->matched($workflow, $message);

        if ($keyword === null) {
            return null;
        }

        return [
            'message' => ['id' => $message->id, 'text' => $message->body],
            'channel' => ['id' => $message->channel_id, 'name' => $message->channel?->name],
            /*
             * Empty for a bot message, which has no person behind it. Left
             * empty rather than filled with the bot name: a workflow that says
             * "Hoi {{ trigger.user.name }}" should come out visibly incomplete
             * rather than greeting another workflow by name.
             */
            'user' => ['id' => $message->user_id, 'name' => $message->author?->name],
            'keyword' => $keyword,
        ];
    }

    /**
     * The first of the workflow's words that this message actually says, or
     * null when it says none of them.
     *
     * The first rather than all: a workflow runs once per message, and handing
     * over the one word that set it off is more useful in a reply than a list
     * would be.
     */
    private function matched(Workflow $workflow, Message $message): ?string
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // No channel named means the whole workspace. Compared loosely because
        // the id came out of a JSON column, where 7 may well be "7".
        if (filled($channelId) && (int) $channelId !== $message->channel_id) {
            return null;
        }

        foreach ((array) $workflow->triggerSetting('keywords', []) as $keyword) {
            $keyword = trim((string) $keyword);

            if ($keyword === '') {
                continue;
            }

            /*
             * On word boundaries, so "storing" does not fire on "restoring".
             * The alternative — a plain contains — is the sort of thing that
             * works in every test somebody writes and then goes off at three in
             * the morning for a word inside another word.
             */
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', (string) $message->body) === 1) {
                return $keyword;
            }
        }

        return null;
    }
}
