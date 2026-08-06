<?php

namespace App\Workflows\Actions\Concerns;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Finding the channel, the message or the person a step was pointed at.
 *
 * Shared because every one of these lookups has the same two halves, and the
 * second half is the one that matters: does it exist, and does it belong to
 * this workspace. Written once, so an action cannot be the one that forgot —
 * a workflow that could name an id from another workspace would be a way to
 * post into places nobody in this one can see.
 *
 * Every failure throws with a sentence somebody can read, because that sentence
 * is what ends up on the run screen. "Kanaal niet gevonden" is a complete
 * answer there in a way that a null would not be.
 */
trait FindsTargets
{
    /**
     * The channel a step names, when it belongs to this workspace and the
     * workflow's owner may see it.
     */
    protected function channel(WorkflowStepContext $context, string $key = 'channel_id'): Channel
    {
        $id = $context->setting($key);

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_channel_chosen'));
        }

        $channel = $this->findChannel($context, (string) $id);

        /*
         * One answer for "no such channel" and "not the owner's to see". They
         * are told apart nowhere else in this application either — see the MCP
         * tools — because telling them apart is a way to find out which ids
         * exist.
         */
        if ($channel === null || $this->actor($context)->cannot('view', $channel)) {
            throw new RuntimeException(__('workflows.errors.channel_not_found'));
        }

        return $channel;
    }

    /**
     * The channel a setting names: by id, or by the name people call it.
     *
     * A name as well as an id because of where these values now come from. A
     * field may hold a variable, and what a trigger knows is usually
     * trigger.channel.name — "meld dit in #storingen" is how somebody thinks
     * about it, and asking them to carry an id through a workflow would be
     * asking them to write something they cannot read back.
     *
     * The hash is stripped because people type it. It is punctuation in the
     * chat, not part of the name — the same rule the slash command applies to
     * its own leading slash.
     *
     * Scoped to the workflow's workspace in every branch, which is the property
     * that makes a variable safe here at all: whatever it resolves to, it can
     * only ever find something this workspace owns.
     */
    private function findChannel(WorkflowStepContext $context, string $named): ?Channel
    {
        $channels = Channel::query()->where('workspace_id', $context->workspace()->id);

        if (ctype_digit($named)) {
            return $channels->whereKey($named)->first();
        }

        $name = ltrim(trim($named), '#');

        /*
         * Case-insensitively, because a name is typed by a person and "#Storingen"
         * and "#storingen" are the same channel to everybody except a database.
         */
        return $channels->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    /**
     * The message a step names.
     *
     * Defaults to the one the trigger was about, which is what almost every
     * workflow means: pinning, replying and reacting are things you do to the
     * message that set the workflow off. A step may name another by writing a
     * variable into the field.
     */
    protected function message(WorkflowStepContext $context, string $key = 'message_id'): Message
    {
        $id = $context->setting($key);

        if (blank($id)) {
            $id = $context->value('trigger.message.id');
        }

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_message'));
        }

        $message = Message::query()
            ->where('workspace_id', $context->workspace()->id)
            ->whereKey($id)
            ->first();

        if ($message === null || $this->actor($context)->cannot('view', $message->channel)) {
            throw new RuntimeException(__('workflows.errors.message_not_found'));
        }

        return $message;
    }

    /** Somebody in this workspace. */
    protected function member(WorkflowStepContext $context, string $key = 'user_id'): User
    {
        $id = $context->setting($key);

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_person_chosen'));
        }

        $member = $context->workspace()->members()->whereKey($id)->first();

        if ($member === null) {
            throw new RuntimeException(__('workflows.errors.person_not_found'));
        }

        return $member;
    }

    /**
     * Whose rights this step runs with.
     *
     * The runner refuses an ownerless workflow before any step gets a turn, so
     * this being null would mean the run got past that check — worth saying out
     * loud rather than carrying on with a permission question nobody is
     * answering.
     */
    protected function actor(WorkflowStepContext $context): User
    {
        $actor = $context->actor();

        if ($actor === null) {
            throw new RuntimeException(__('workflows.errors.no_owner'));
        }

        return $actor;
    }

    /**
     * The name a workflow's messages appear under.
     *
     * Whatever the workflow says to sign them with, marked as a bot the way
     * every other automatic message in this application is — see
     * Workflow::botName() for why an empty box falls back to the workflow's own
     * name, and never to the owner's.
     */
    protected function botName(WorkflowStepContext $context): string
    {
        return $context->workflow->botName();
    }
}
