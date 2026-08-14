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
     * Deciding who belongs here, and in what capacity.
     *
     * The ledenlijst in the workspace settings and everything that can be done
     * from it: changing somebody's role, setting which channels a guest sits
     * in, and showing somebody the door.
     *
     * Carved out of ManageWorkspace rather than left inside it, because the two
     * are about different things. Managing the workspace is about the place —
     * its name, its roles, its look. This is about the people in it, and
     * removing somebody takes them out of every channel they were in at once.
     * A workspace that wants an office manager who arranges who comes and goes,
     * without handing them the settings, could not say that before.
     *
     * It stops short of inviting, which is InviteMembers and stays its own
     * right: bringing somebody in and putting somebody out are not the same
     * decision, and plenty of workspaces want the first spread wider than the
     * second.
     *
     * Holding this is necessary but not sufficient for any particular person.
     * Nobody may act on a role that stands above their own or holds a right
     * they lack — see Role::isUnder — so this widens *who* may administer
     * members, never *whom* they may reach.
     */
    case ManageMembers = 'manage-members';

    /**
     * Looking at the workspace through somebody else's eyes.
     *
     * The heaviest right in this catalogue, and the only one that is not about
     * what you may do but about who you may be. While it is running there is no
     * difference between the two of you: every screen, every private channel,
     * every DM and every draft of that person is open, and anything written is
     * written under their name with no mark on it anywhere.
     *
     * It exists because the alternative is worse. Somebody says "ik zie mijn
     * kanaal niet" and the only honest answer without this is "stuur je
     * wachtwoord even" — which is what people then actually do.
     *
     * Off for every seeded role but the owner, for the reason SeeHours is: it
     * is handed out on purpose or not at all. Notably not with ManageMembers,
     * although this is reached from the ledenlijst: arranging who comes and
     * goes is about their standing, and this is about their inbox.
     *
     * It never reaches further than the holder does. Nobody may step into a
     * role that stands above their own — the same Role::isUnder that guards
     * changing somebody's role — so this widens who may look, never what the
     * looking is worth. See WorkspacePolicy::impersonate for the rest of the
     * fence, and RefuseWhileImpersonating for what stays shut once you are in.
     */
    case ImpersonateMembers = 'impersonate-members';

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
     * Throwing away a contract everybody has already signed.
     *
     * Apart from SendContracts, and not a degree of it. Everything else in this
     * feature adds to the record; this is the only thing that takes a finished
     * one off the disk — the signed PDF, the audit page, the signatures and the
     * hash that ties them to the document. The person on the other end has a
     * copy and every reason to assume ours still exists.
     *
     * So it is asked as its own question. Somebody trusted to have the terms
     * signed is not thereby trusted to make a signed set of terms disappear,
     * and a workspace that wants that in one pair of hands — or in nobody's —
     * has to be able to say so.
     *
     * Off for every seeded role but the owner, for the reason SeeHours is: it
     * is handed out on purpose or not at all. What it does *not* widen is who
     * can see the contract; a right to delete something you may not open would
     * be a way to clear out other people's work by id.
     */
    case DeleteSignedContracts = 'delete-signed-contracts';

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
     * Deciding which parts of the product this workspace offers at all.
     *
     * Not part of ManageWorkspace, and the widest right in this catalogue after
     * it. Everything else a beheerder changes is a setting *within* a feature —
     * how large an attachment may be, which words are refused, who may invite.
     * This one decides whether the feature exists: switching it off takes the
     * documents, the contracts or the uren out of reach for everybody at once,
     * and the data stays behind a door nobody in the workspace can open.
     *
     * Off for every seeded role but the owner, for the reason SeeHours is: it
     * is handed out on purpose or not at all. Somebody hired to run the day to
     * day is not thereby the person who decides what the workspace is.
     */
    case ManageFeatures = 'manage-features';

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
            self::ManageMembers => __('enums.workspace-ability.label.ManageMembers'),
            self::ImpersonateMembers => __('enums.workspace-ability.label.ImpersonateMembers'),
            self::SeeHours => __('enums.workspace-ability.label.SeeHours'),
            self::ManageFeatures => __('enums.workspace-ability.label.ManageFeatures'),
            self::DeleteBotMessages => __('enums.workspace-ability.label.DeleteBotMessages'),
            self::ShareFormsPublicly => __('enums.workspace-ability.label.ShareFormsPublicly'),
            self::SendContracts => __('enums.workspace-ability.label.SendContracts'),
            self::DeleteSignedContracts => __('enums.workspace-ability.label.DeleteSignedContracts'),
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
            self::ManageMembers => __('enums.workspace-ability.description.ManageMembers'),
            self::ImpersonateMembers => __('enums.workspace-ability.description.ImpersonateMembers'),
            self::SeeHours => __('enums.workspace-ability.description.SeeHours'),
            self::ManageFeatures => __('enums.workspace-ability.description.ManageFeatures'),
            self::DeleteBotMessages => __('enums.workspace-ability.description.DeleteBotMessages'),
            self::ShareFormsPublicly => __('enums.workspace-ability.description.ShareFormsPublicly'),
            self::SendContracts => __('enums.workspace-ability.description.SendContracts'),
            self::DeleteSignedContracts => __('enums.workspace-ability.description.DeleteSignedContracts'),
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
