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
    case CreateForms = 'create-forms';

    /**
     * Handing a form to the world.
     *
     * Its own right rather than part of the one above, because the two differ
     * in direction: making a form asks colleagues something, sharing it as a
     * link invites the outside to write into this workspace. Somebody trusted
     * to do the first is not automatically trusted to do the second — the same
     * split SendTransfers is kept apart for.
     */
    case ShareFormsPublicly = 'share-forms-publicly';

    /**
     * Asking somebody for their signature.
     *
     * Its own right rather than folded into SendTransfers, although both hand
     * something to the outside world. What separates them is what comes back: a
     * transfer is finished the moment the recipient has the files, while a
     * contract asks a person to put their name under something on behalf of
     * this workspace. Being trusted to send a customer their invoices does not
     * follow from that, and neither does the reverse.
     */
    case SendContracts = 'send-contracts';

    /**
     * Reading what the clock recorded about other people.
     *
     * Its own right, and off for every role until a workspace says otherwise —
     * including for whoever manages the place. Managing a workspace is about
     * its channels, its roles and its settings; looking at when a colleague
     * started and stopped working is about a person, and the two do not follow
     * from one another. A workspace that wants its manager to have it says so.
     *
     * Nobody needs it to see their own hours. That is not a right, it is the
     * member reading their own row.
     */
    case SeeHours = 'see-hours';

    /**
     * Taking down what an integration said.
     *
     * A message from a bot is nobody's own words, so the rule that carries
     * every other deletion — "your own words are yours" — has nothing to grip.
     * Somebody has to be able to clear up a webhook that fired fifty times or a
     * workflow that posted the wrong thing into the wrong channel.
     *
     * Until now that somebody was whoever configures the channel, on the
     * reasoning that they also create and revoke its webhooks. That rule stays;
     * this right sits beside it, because the two answer different questions.
     * "Mag jij opruimen wat de bots posten" should not have to be answered with
     * "dan geef ik je maar het hele kanaal".
     */
    case DeleteBotMessages = 'delete-bot-messages';

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
            self::CreateForms => __('enums.workspace-ability.label.CreateForms'),
            self::SeeHours => __('enums.workspace-ability.label.SeeHours'),
            self::DeleteBotMessages => __('enums.workspace-ability.label.DeleteBotMessages'),
            self::ShareFormsPublicly => __('enums.workspace-ability.label.ShareFormsPublicly'),
            self::SendContracts => __('enums.workspace-ability.label.SendContracts'),
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
            self::CreateForms => __('enums.workspace-ability.description.CreateForms'),
            self::SeeHours => __('enums.workspace-ability.description.SeeHours'),
            self::DeleteBotMessages => __('enums.workspace-ability.description.DeleteBotMessages'),
            self::ShareFormsPublicly => __('enums.workspace-ability.description.ShareFormsPublicly'),
            self::SendContracts => __('enums.workspace-ability.description.SendContracts'),
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
