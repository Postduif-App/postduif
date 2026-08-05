<?php

namespace App\Enums;

/**
 * Something a role may be given, or not.
 *
 * The catalogue: one closed list that the settings screen draws its tickboxes
 * from, the policies ask, and the public page reads. Closed rather than
 * free-form because every one of these has to be enforced somewhere — a right
 * a workspace could invent would be a tickbox that does nothing, which is worse
 * than not offering it.
 *
 * What is deliberately *not* here is whether a role can see the workspace at
 * all. That question is asked in SQL — see Workspace::scopeBrowsableBy and
 * Channel::scopeVisibleTo — so it lives as a column on the role rather than as
 * an entry in a bag. A guest does not have fewer rights to the channels they
 * cannot see: for a guest those channels do not exist, and only a column can
 * say that to a query.
 */
enum WorkspaceAbility: string
{
    case ManageWorkspace = 'manage-workspace';
    case InviteMembers = 'invite-members';
    case SeeMembers = 'see-members';
    case CreateChannels = 'create-channels';
    case SendTransfers = 'send-transfers';
    case BroadcastMention = 'broadcast-mention';
    case ManageWorkflows = 'manage-workflows';

    public function label(): string
    {
        return match ($this) {
            self::ManageWorkspace => __('enums.workspace-ability.label.ManageWorkspace'),
            self::InviteMembers => __('enums.workspace-ability.label.InviteMembers'),
            self::SeeMembers => __('enums.workspace-ability.label.SeeMembers'),
            self::CreateChannels => __('enums.workspace-ability.label.CreateChannels'),
            self::SendTransfers => __('enums.workspace-ability.label.SendTransfers'),
            self::BroadcastMention => __('enums.workspace-ability.label.BroadcastMention'),
            self::ManageWorkflows => __('enums.workspace-ability.label.ManageWorkflows'),
        };
    }

    /**
     * What granting it actually means, in a sentence.
     *
     * Every one of these is a decision somebody makes about other people, so
     * the screen says what it does rather than leaving a two-word label to
     * carry it. "Beheren" is not a thing anybody can weigh.
     */
    public function description(): string
    {
        return match ($this) {
            self::ManageWorkspace => __('enums.workspace-ability.description.ManageWorkspace'),
            self::InviteMembers => __('enums.workspace-ability.description.InviteMembers'),
            self::SeeMembers => __('enums.workspace-ability.description.SeeMembers'),
            self::CreateChannels => __('enums.workspace-ability.description.CreateChannels'),
            self::SendTransfers => __('enums.workspace-ability.description.SendTransfers'),
            self::BroadcastMention => __('enums.workspace-ability.description.BroadcastMention'),
            self::ManageWorkflows => __('enums.workspace-ability.description.ManageWorkflows'),
        };
    }

    /**
     * Whether holding this one is enough to hand out the rest.
     *
     * Managing the workspace is where the roles themselves are edited, so it is
     * the one right that can reach every other. Named here rather than compared
     * against in three policies, because "which right is the dangerous one" is a
     * fact about the catalogue.
     */
    public function isKeyToTheRest(): bool
    {
        return $this === self::ManageWorkspace;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
