<?php

namespace App\Policies;

use App\Enums\WorkspaceAbility;
use App\Features\Polls;
use App\Features\SecretRequests;
use App\Features\Transfers;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Role;
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
        return $workspace->allows($user, WorkspaceAbility::BroadcastMention);
    }

    /**
     * Changing workspace-wide settings is for whoever runs the workspace.
     */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspaceAbility::ManageWorkspace);
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
        return $workspace->allows($user, WorkspaceAbility::InviteMembers);
    }

    /**
     * Whether this member may open a new channel here.
     *
     * One question now where there were two. Who may open a channel used to be
     * a role predicate and a workspace setting that had to agree; both said the
     * same thing in different words, and a workspace that wanted its channel
     * list curated said it in the second. It is a right on a role now, and the
     * setting seeded it.
     */
    public function createChannel(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspaceAbility::CreateChannels);
    }

    /**
     * Whether this member may put files behind a shareable download link.
     *
     * Two gates, as with createChannel and in the same order. The feature comes
     * first because a workspace that has not switched it on has no such thing
     * at all — the route middleware says the same in 404 form, and this is what
     * lets a screen leave the button off rather than draw one that refuses.
     *
     * Then the role, which keeps guests out: see canSendTransfers().
     */
    public function createTransfer(User $user, Workspace $workspace): bool
    {
        if (! $workspace->hasFeature(Transfers::class)) {
            return false;
        }

        return $workspace->allows($user, WorkspaceAbility::SendTransfers);
    }

    /**
     * Whether this member may ask somebody for a password or a key.
     *
     * The same two gates as createTransfer, and the guest rule points the other
     * way round from what you might expect: a guest is usually the one being
     * asked — the customer with the credentials — so asking is for the people
     * who belong here. Filling one in is not judged by this at all; see
     * SecretRequestPolicy.
     */
    public function createSecretRequest(User $user, Workspace $workspace): bool
    {
        if (! $workspace->hasFeature(SecretRequests::class)) {
            return false;
        }

        return ! $workspace->isExternal($user);
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
        return ! $workspace->isExternal($user);
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

        if (! $workspace->isExternal($user)) {
            return true;
        }

        return $workspace->hasSharedChannel($user, $target);
    }

    /**
     * Whether this member may write workflows for this workspace.
     *
     * The same two gates as createTransfer, and the role gate is the strictest
     * one in this file on purpose: a workflow can archive a channel, add people
     * to it and post in any of them, and it does so with the rights of whoever
     * wrote it rather than of whoever set it off. That is administering the
     * workspace by another name, so manage() is the honest question to ask.
     */
    public function manageWorkflows(User $user, Workspace $workspace): bool
    {
        if (! $workspace->hasFeature(WorkflowsFeature::class)) {
            return false;
        }

        return $this->manage($user, $workspace);
    }

    /**
     * Whether this member may change somebody's standing, their own included.
     *
     * Nobody may touch somebody whose role stands above their own — see
     * Role::isUnder for what that means and why it is two questions. This used
     * to read "only an owner may touch an owner", which is the same rule in a
     * world with four fixed roles and no rule at all in one where a workspace
     * writes its own.
     *
     * Editing your own row is allowed on purpose. Forbidding it looks safer but
     * leaves a sole owner unable to hand the workspace over — they can appoint
     * a second and then never step down. What actually needs protecting is that
     * somebody who can manage the place remains at all, and that is guarded
     * when the change is applied rather than by who is making it.
     */
    public function updateMemberRole(User $user, Workspace $workspace, User $target): bool
    {
        if (! $this->manage($user, $workspace)) {
            return false;
        }

        $targetRole = $workspace->roleFor($target);
        $ownRole = $workspace->roleFor($user);

        if ($targetRole === null || $ownRole === null) {
            return false;
        }

        return $targetRole->isUnder($ownRole);
    }

    /**
     * Whether this member may hand out a particular role.
     *
     * The rule that keeps custom roles from being a way to promote yourself:
     * you may not give away a right you do not hold, nor a role that stands
     * above your own. Without it, "make a role and assign it" is a two-step
     * path from administrator to everything — and it is a path no screen can
     * close, because the screen is where roles are made.
     */
    public function grantRole(User $user, Workspace $workspace, Role $role): bool
    {
        $ownRole = $workspace->roleFor($user);

        return $ownRole !== null && $role->isUnder($ownRole);
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

        return $workspace->isExternal($target);
    }

    /**
     * Removing somebody follows the same shape as changing their role, with one
     * addition: nobody removes themselves from this screen. Walking out of a
     * workspace is a different thing from being shown the door, and it does not
     * belong on the page where you administer other people.
     *
     * An owner may now be shown the door, which they could not be before. There
     * can be several, so refusing outright was protecting a rule that no longer
     * holds — what has to survive is that somebody who can manage the place
     * remains, and that is guarded where the change is applied.
     */
    public function removeMember(User $user, Workspace $workspace, User $target): bool
    {
        if ($user->is($target)) {
            return false;
        }

        return $this->updateMemberRole($user, $workspace, $target);
    }
}
