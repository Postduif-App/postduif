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

        $channel = Channel::query()
            ->where('workspace_id', $context->workspace()->id)
            ->whereKey($id)
            ->first();

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
     * The workflow's own name, marked as a bot the way every other automatic
     * message in this application is. Never the owner's name: a message that
     * looked like a colleague saying something they never said is the one
     * outcome this whole feature must not produce.
     */
    protected function botName(WorkflowStepContext $context): string
    {
        return $context->workflow->name;
    }
}
