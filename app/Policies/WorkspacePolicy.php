<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Features\Polls;
use App\Features\SecretRequests;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Everything below the membership checks is the admin panel talking. A
     * platform moderator is granted these abilities explicitly rather than
     * through a policy-wide before(), so nothing they can do is implicit.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user) || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Whether this member may use a mention that reaches the whole channel at
     * once. The workspace decides how open that is; see BroadcastMentionPolicy.
     */
    public function broadcastMention(User $user, Workspace $workspace): bool
    {
        return $workspace->broadcast_mentions->allows($workspace, $user);
    }

    /**
     * Changing workspace-wide settings is for whoever runs the workspace.
     */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user)?->canManageWorkspace() ?? false;
    }

    /**
     * Whether this member may invite somebody in — a new member, or a guest
     * with a handful of channels.
     *
     * Asked through the role rather than through manage(), because who may
     * bring people in is a decision the role makes for itself; today it happens
     * to give the same answer.
     */
    public function invite(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user)?->canInviteMembers() ?? false;
    }

    /**
     * Whether this member may open a new channel here.
     *
     * Two gates, and the order matters. The role decides first: a guest is
     * present for the channels they were invited to, and a channel of their own
     * making is not one of them — no workspace setting can hand them that.
     * Only then does the workspace's own policy get a say, which is where a
     * workspace that wants its channel list curated closes the door on plain
     * members.
     */
    public function createChannel(User $user, Workspace $workspace): bool
    {
        $role = $workspace->roleFor($user);

        if ($role === null || ! $role->canCreateChannels()) {
            return false;
        }

        return $workspace->channel_creation->allows($role);
    }

    /**
     * Whether this member may ask somebody for a password or a key.
     *
     * The guest rule points the other way round from what you might expect: a
     * guest is usually the one being asked — the customer with the
     * credentials — so asking is for the people who belong here. Filling one
     * in is not judged by this at all; see SecretRequestPolicy.
     */
    public function createSecretRequest(User $user, Workspace $workspace): bool
    {
        if (! $workspace->hasFeature(SecretRequests::class)) {
            return false;
        }

        return $workspace->roleFor($user)?->canBrowseWorkspace() ?? false;
    }

    /**
     * Whether this member may put a question to a channel.
     *
     * Guests included, unlike asking for secrets: a guest is in the channel and
     * a question about when to meet is as much about them as anybody. What
     * keeps this honest is the channel's own posting rule, checked separately —
     * a poll is a message, and it lands where messages are allowed.
     */
    public function createPoll(User $user, Workspace $workspace): bool
    {
        return $workspace->hasFeature(Polls::class) && $workspace->hasMember($user);
    }

    /**
     * Sending one message into several channels at once.
     *
     * Not for a guest, unlike posting itself. A guest is in the workspace for
     * the channels they were put in; addressing several of them in one go is
     * something you do when you have an overview of the place, and it leans on
     * the tags — which a guest does not get to see either.
     */
    public function broadcastToChannels(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user)?->canBrowseWorkspace() ?? false;
    }

    /**
     * Whether this member may open a conversation with somebody at all.
     *
     * Everyone who belongs here may, guests included: a guest is cut off from
     * the workspace, not from the people they were put in a channel with. Which
     * people those are is directMessage()'s question, and this one only decides
     * whether the button exists.
     */
    public function startDirectMessage(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    /**
     * Whether this member may open a conversation with this particular person.
     *
     * The guest rule is the whole point. A guest may not browse the workspace
     * or see who is in it, so letting them address anyone by id would hand back
     * the members list through the DM picker — and filtering only the picker
     * would leave the same hole one hand-written request wide. Both the
     * candidate search and the endpoint that creates the channel ask here.
     *
     * Being addressed is not restricted in the same way: a member who writes to
     * a guest is choosing to make themselves known, which is theirs to choose.
     */
    public function directMessage(User $user, Workspace $workspace, User $target): bool
    {
        if ($user->is($target) || ! $workspace->hasMember($target)) {
            return false;
        }

        if (! $this->startDirectMessage($user, $workspace)) {
            return false;
        }

        if ($workspace->roleFor($user)?->canBrowseWorkspace() ?? false) {
            return true;
        }

        return $workspace->hasSharedChannel($user, $target);
    }

    /**
     * Whether this member may change somebody's standing, their own included.
     *
     * Only an owner may touch an owner: an admin who could demote the owner
     * would effectively outrank them, which is not what the roles say.
     *
     * Editing your own row is allowed on purpose. Forbidding it looks safer but
     * leaves a sole owner unable to hand the workspace over — they can appoint
     * a second owner and then never step down. What actually needs protecting
     * is that an owner remains at all, and that is guarded when the change is
     * applied rather than by who is making it.
     */
    public function updateMemberRole(User $user, Workspace $workspace, User $target): bool
    {
        if (! $this->manage($user, $workspace)) {
            return false;
        }

        $targetRole = $workspace->roleFor($target);

        if ($targetRole === null) {
            return false;
        }

        return $targetRole !== WorkspaceRole::Owner
            || $workspace->roleFor($user) === WorkspaceRole::Owner;
    }

    /**
     * Whether this member may grant a particular role.
     *
     * Handing out ownership is the owner's alone; an admin who could appoint
     * owners could appoint themselves.
     */
    public function grantRole(User $user, Workspace $workspace, WorkspaceRole $role): bool
    {
        return $role !== WorkspaceRole::Owner
            || $workspace->roleFor($user) === WorkspaceRole::Owner;
    }

    /**
     * Whether this member may decide which channels a guest belongs to.
     *
     * Only guests: for anybody else the channel list is not a permission but a
     * record of where they went themselves, and editing it from an admin screen
     * would be pulling people out of conversations rather than administering
     * access. Adding a regular member to a channel is what the channel's own
     * member list is for.
     */
    public function manageGuestChannels(User $user, Workspace $workspace, User $target): bool
    {
        if (! $this->manage($user, $workspace)) {
            return false;
        }

        return $workspace->roleFor($target)?->isGuest() ?? false;
    }

    /**
     * Removing somebody follows the same shape as changing their role, with two
     * additions.
     *
     * The owner cannot be removed at all. Ownership has to be handed over
     * first, which keeps every workspace with somebody answerable for it.
     *
     * And nobody removes themselves from this screen. Walking out of a
     * workspace is a different thing from being shown the door, and it does not
     * belong on the page where you administer other people.
     */
    public function removeMember(User $user, Workspace $workspace, User $target): bool
    {
        if ($user->is($target) || $workspace->roleFor($target) === WorkspaceRole::Owner) {
            return false;
        }

        return $this->updateMemberRole($user, $workspace, $target);
    }
}
