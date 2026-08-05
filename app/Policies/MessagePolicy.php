<?php

namespace App\Policies;

use App\Enums\WorkspaceAbility;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Reading every message on the platform, which only the admin panel does.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Message $message): bool
    {
        return $user->isAdmin();
    }

    /**
     * Rewriting a message, which only its author may do.
     *
     * Narrower than delete() on both ends, and deliberately so. A platform
     * moderator may take something down but not put different words in
     * somebody's mouth — removal is visible as removal, while an edit is
     * indistinguishable from what the author typed. A bot message has no author
     * to speak for either: it says what the integration sent, and the way to
     * change that is at the integration.
     *
     * A deleted message is nothing but a tombstone, so there is no text left to
     * change.
     */
    public function update(User $user, Message $message): bool
    {
        if ($message->isFromBot() || $message->isDeleted()) {
            return false;
        }

        return $message->user_id === $user->id;
    }

    /**
     * Putting a message at the top of its channel, and taking it back down.
     *
     * Whoever may configure the channel, and nobody else. A pin here is the
     * channel intro and the house rules rather than a personal bookmark, so it
     * is an editorial act: the same people who decide what the channel is for
     * decide what everyone reads first. Two consequences fall straight out of
     * manageSettings — a DM has nothing to configure, so nobody pins there, and
     * an archived channel is closed to this as it is to everything else.
     *
     * A deleted message cannot be pinned: there is no text left, only a marker.
     * Unpinning one is not something anybody has to do either, because
     * DeleteMessage already takes the pin off on the way out.
     */
    public function pin(User $user, Message $message): bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        return $user->can('manageSettings', $message->channel);
    }

    /**
     * The author, or a platform moderator.
     *
     * Within a workspace the rule is still "your own words are yours": a channel
     * creator or workspace admin cannot remove other people's messages, because
     * that needs rules about who may do it that the workspace does not have.
     * Platform moderation is the exception — it exists precisely to take down
     * what nobody inside the workspace can, and it goes through the same
     * DeleteMessage action, so the channel sees the removal either way.
     */
    public function delete(User $user, Message $message): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        /*
         * A bot message is nobody's own words, so "the author" cannot clean it
         * up. Without an owner it would take a platform moderator to remove a
         * misfiring integration's output, which is too far away from the
         * problem.
         *
         * Two answers, and either is enough. Whoever may configure the channel
         * — and therefore may create and revoke its webhooks — may delete what
         * they post there; that was the original rule and it stays, because
         * taking it away would quietly remove something people already have.
         *
         * Beside it sits a right the workspace hands out, for the case that
         * rule could not express: somebody who should be able to tidy up after
         * the integrations without being handed the channel itself. Workspace
         * wide rather than per channel, like every other ability — a right that
         * had to be granted per channel would be a second permission system
         * living beside the roles.
         */
        if ($message->isFromBot()) {
            return $user->can('manageSettings', $message->channel)
                || $message->workspace->allows($user, WorkspaceAbility::DeleteBotMessages);
        }

        return $message->user_id === $user->id;
    }
}
