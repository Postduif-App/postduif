<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkspaceRole: string implements HasLabel
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
            self::Owner => 'Eigenaar',
            self::Admin => 'Beheerder',
            self::Member => 'Lid',
            self::Guest => 'Gast',
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
