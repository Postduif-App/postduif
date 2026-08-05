<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SystemRole: string implements HasLabel
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * An external participant. A guest belongs to the workspace only far enough
     * to be a member of the channels they were invited to: they cannot reach a
     * public channel they were not put in, and they cannot see who else is in
     * the workspace. Everywhere else in the codebase "member" means "may see the
     * workspace", so this case is the one that has to be asked about explicitly.
     */
    case Guest = 'guest';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => __('enums.system-role.getLabel.Owner'),
            self::Admin => __('enums.system-role.getLabel.Admin'),
            self::Member => __('enums.system-role.getLabel.Member'),
            self::Guest => __('enums.system-role.getLabel.Guest'),
        };
    }

    public function canManageWorkspace(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canInviteMembers(): bool
    {
        return $this->canManageWorkspace();
    }

    public function isGuest(): bool
    {
        return $this === self::Guest;
    }

    /**
     * Whether the workspace is browsable: its public channels, and who else is
     * in it. False only for guests, and the reason every visibility check asks
     * this rather than comparing against the case directly — a later role that
     * is equally restricted then gets the same treatment for free.
     */
    public function canBrowseWorkspace(): bool
    {
        return ! $this->isGuest();
    }

    /**
     * Whether this role may see who is in a channel, and change it.
     *
     * Both in one question on purpose: managing a list you may not read is not
     * a thing anyone needs. False for guests, which is what keeps the member
     * list — and the picker behind it, which searches the whole workspace —
     * out of a guest's reach. Leaving a channel yourself is a different
     * ability and stays open to them.
     */
    public function canSeeChannelMembers(): bool
    {
        return ! $this->isGuest();
    }

    /**
     * Whether this role may open a new channel.
     *
     * Not folded into canBrowseWorkspace() even though both are false only for
     * guests: browsing is about what you get to see, this is about what you get
     * to add to the workspace, and the day those answers differ is the day the
     * shared method would have been wrong for one of them.
     */
    public function canCreateChannels(): bool
    {
        return ! $this->isGuest();
    }

    /**
     * Whether this role may put files behind a link for the outside world.
     *
     * False for guests, and for a stronger reason than the two above: a guest
     * is somebody from outside who was let into a few channels, and a transfer
     * hands out a link that spends this workspace's storage and carries its
     * name. Letting the outside make links on our behalf is the one direction
     * this feature must not run in.
     */
    public function canSendTransfers(): bool
    {
        return ! $this->isGuest();
    }

    /**
     * What a workspace starts with, before anybody edits anything.
     *
     * This enum stops being the answer to "may they?" once a workspace has
     * roles of its own — see SystemRole model — and becomes the seed for
     * them instead. Written here rather than in a seeder because it is the same
     * list the predicates above already encode: change one and this has to
     * follow, and having them side by side is what makes that obvious.
     *
     * @return list<WorkspaceAbility>
     */
    public function defaultAbilities(): array
    {
        return array_values(array_filter(WorkspaceAbility::cases(), fn (WorkspaceAbility $ability): bool => match ($ability) {
            WorkspaceAbility::ManageWorkspace,
            WorkspaceAbility::InviteMembers,
            WorkspaceAbility::ManageWorkflows => $this->canManageWorkspace(),

            /*
             * Everybody but a guest, which is what the predicates above say
             * today. Broadcast mentions are the exception: the workspace column
             * that governs them starts at "beheerders", so the seed follows the
             * column rather than the role — see the migration, which reads the
             * workspace's own setting where there is one.
             */
            WorkspaceAbility::BroadcastMention => $this->canManageWorkspace(),

            WorkspaceAbility::SeeMembers => $this->canSeeChannelMembers(),
            WorkspaceAbility::CreateChannels => $this->canCreateChannels(),
            WorkspaceAbility::SendTransfers => $this->canSendTransfers(),
        }));
    }

    /**
     * Whether somebody in this role is from outside.
     *
     * The one answer that becomes a column rather than a right: it decides what
     * exists for them rather than what they may do with it, and that decision
     * is made in SQL.
     */
    public function isExternal(): bool
    {
        return $this->isGuest();
    }

    /**
     * The same question as canBrowseWorkspace(), in the form a query can ask
     * it: the role values that may see the workspace's public channels.
     *
     * Derived from the predicate rather than listed by hand, so a role added
     * later cannot be browsable in PHP and invisible in SQL, or the reverse.
     *
     * @return array<int, string>
     */
    public static function browsingValues(): array
    {
        return array_values(array_map(
            fn (self $role): string => $role->value,
            array_filter(self::cases(), fn (self $role): bool => $role->canBrowseWorkspace()),
        ));
    }

    /**
     * The order roles are listed in: whoever runs the workspace first, guests
     * last. Kept here so the sort order cannot drift from the cases above.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 0,
            self::Admin => 1,
            self::Member => 2,
            self::Guest => 3,
        };
    }
}
