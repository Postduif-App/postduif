<?php

namespace App\Policies;

use App\Enums\ChannelType;
use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    /**
     * Moderation abilities, used by the admin panel. Deliberately not folded
     * into view(): a moderator may act on a channel from /admin without
     * silently gaining read access to private conversations in the chat UI.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $user->isAdmin();
    }

    /**
     * Public channels are readable by every workspace member who may browse the
     * workspace; private channels, DMs, and everything a guest sees only by
     * their explicit members.
     *
     * The record-by-record twin of Channel::scopeVisibleTo(). Both have to
     * answer the same, or a channel drops out of the sidebar while its URL
     * still opens — or the reverse, which is worse.
     */
    public function view(User $user, Channel $channel): bool
    {
        $role = $channel->workspace->roleFor($user);

        if ($role === null) {
            return false;
        }

        if ($channel->type === ChannelType::Public && ! $role->is_external) {
            return true;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Starting a new message in the channel.
     *
     * Membership is the floor, even in a public channel: reading is open,
     * writing means you joined. On top of that sits the channel's own posting
     * policy, which is what turns a channel into one people only read.
     */
    public function post(User $user, Channel $channel): bool
    {
        if (! $this->contribute($user, $channel)) {
            return false;
        }

        return $channel->posting_policy->allows($channel, $user);
    }

    /**
     * Answering in a thread.
     *
     * Deliberately not routed through post(): a channel that only admins may
     * post in still has to let everyone answer, or an announcement becomes
     * unanswerable.
     *
     * Shutting threads is the separate setting this used to point at. A feed
     * that announces and does not discuss needs it, and folding it into the
     * posting policy would have made "only admins post" and "nobody answers"
     * the same choice — which is exactly the pairing an announcement channel
     * does not want.
     */
    public function reply(User $user, Channel $channel): bool
    {
        return $channel->replies_open && $this->contribute($user, $channel);
    }

    /**
     * Putting an emoji on somebody's message — the lightest way to answer, and
     * open to every member for the same reason replying is.
     */
    public function react(User $user, Channel $channel): bool
    {
        return $this->contribute($user, $channel);
    }

    /**
     * The floor under every kind of contribution: you joined, and the channel
     * is still open.
     */
    private function contribute(User $user, Channel $channel): bool
    {
        if ($channel->archived_at !== null) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Changing how the channel itself works.
     *
     * Note the neighbour above: update() means the platform moderator in the
     * admin panel. This is the workspace-side ability — the person who made the
     * channel, plus whoever runs the workspace. A DM has nothing to configure
     * and no owner, so it is nobody's to change.
     */
    public function manageSettings(User $user, Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->archived_at !== null) {
            return false;
        }

        if ($channel->created_by === $user->id) {
            return $channel->members()->whereKey($user->id)->exists();
        }

        return $channel->workspace->allows($user, WorkspaceAbility::ManageWorkspace);
    }

    /**
     * Taking the channel away for good, with everything ever said in it.
     *
     * The workspace-side twin of delete() above, which is the platform
     * moderator's right in the admin panel — the same split as manageSettings
     * against update().
     *
     * Wider than manageSettings in one place: an archived channel may still be
     * deleted. Archiving is what you do to a channel you are done with, so
     * refusing to delete it afterwards would leave the ones most worth clearing
     * out as the only ones that cannot go.
     *
     * A DM is nobody's to delete. Both people wrote it, and the endpoint for
     * "I no longer want to see this" is hiding it, which leaves the other
     * side's copy alone.
     */
    public function deleteChannel(User $user, Channel $channel): bool
    {
        return $this->answersForChannel($user, $channel);
    }

    /**
     * Putting the channel away, and taking it back out.
     *
     * The reversible half of the pair above, and the same people: whoever may
     * end a channel for good may certainly end it for now. Both sides live in
     * one ability because both are the same question — an archived channel that
     * only a platform moderator could reopen would be a door that locks behind
     * you.
     */
    public function archiveChannel(User $user, Channel $channel): bool
    {
        return $this->answersForChannel($user, $channel);
    }

    /**
     * Whoever answers for a channel: the person who made it and is still in it,
     * or whoever runs the workspace.
     *
     * A DM has no owner and nothing to answer for — both people wrote it — so
     * it is nobody's to end or to put away.
     *
     * Deliberately not manageSettings: that one refuses on an archived channel,
     * which is exactly the state these two have to work in.
     */
    private function answersForChannel(User $user, Channel $channel): bool
    {
        if ($channel->isDirect()) {
            return false;
        }

        if ($channel->created_by === $user->id) {
            return $channel->members()->whereKey($user->id)->exists();
        }

        return $channel->workspace->allows($user, WorkspaceAbility::ManageWorkspace);
    }

    /**
     * Joining a public channel on your own initiative. A guest cannot: the
     * channels they are in were chosen for them when they were invited, and
     * letting them walk into a public one would undo that by the back door.
     * Somebody already inside can still add them, through addMembers().
     */
    public function join(User $user, Channel $channel): bool
    {
        return $channel->type === ChannelType::Public
            && $channel->archived_at === null
            && ! $channel->workspace->isExternal($user);
    }

    /**
     * Opening the member list of a channel.
     *
     * Everything somebody may not know about the workspace sits behind this one
     * button: who is in the channel, and — for whoever may add people — a
     * search box over every member of the workspace. So a role without that
     * right does not get to open it at all, rather than getting a hollowed-out
     * version of it.
     */
    public function viewMembers(User $user, Channel $channel): bool
    {
        if (! $channel->workspace->allows($user, WorkspaceAbility::SeeMembers)) {
            return false;
        }

        return $this->view($user, $channel);
    }

    /**
     * Anyone already inside a channel may bring someone else in — the same rule
     * Slack uses, and the only one that works without an owner concept.
     *
     * Except a guest, who was placed in their channels by whoever invited them
     * and does not get to widen that circle. Note this also shuts the candidate
     * search behind it, which lists the whole workspace.
     *
     * A DM is excluded: adding a third person to a two-person conversation
     * would silently change what everyone in it thought they were writing in.
     */
    public function addMembers(User $user, Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->archived_at !== null) {
            return false;
        }

        if (! $this->viewMembers($user, $channel)) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Leaving is allowed, with two exceptions.
     *
     * A DM has no meaning with one participant left in it. And the channel's
     * creator cannot walk out: they are the only member with a claim to it, so
     * their leaving would strand a private channel with nobody responsible for
     * who gets in. Hand it over first — once ownership can be transferred, this
     * is the check that should learn about it.
     */
    public function leave(User $user, Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->created_by === $user->id) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Removing someone else follows the same rule as adding them: if you are in
     * the channel, you can manage who else is. The creator is exempt for the
     * same reason they cannot leave.
     */
    public function removeMember(User $user, Channel $channel, User $target): bool
    {
        if ($channel->isDirect() || $channel->archived_at !== null) {
            return false;
        }

        if ($channel->created_by === $target->id) {
            return false;
        }

        if (! $this->viewMembers($user, $channel)) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists()
            && $channel->members()->whereKey($target->id)->exists();
    }
}
