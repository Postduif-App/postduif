<?php

namespace App\Enums;

use App\Models\Channel;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;

/**
 * Who may start a new message in a channel.
 *
 * This governs posting only. Reacting and replying in a thread stay open to
 * every member whatever this says, because a channel where nobody may answer is
 * a noticeboard, not a conversation — and answering in a thread is exactly how
 * an announcement channel stays usable.
 */
enum ChannelPostingPolicy: string implements HasLabel
{
    case Everyone = 'everyone';
    case Admins = 'admins';

    /**
     * Membership is checked before this runs; this only narrows it further.
     *
     * The channel's creator counts as an admin here: they made the channel in
     * order to announce something, so locking them out of their own channel
     * would be the one outcome nobody wants.
     */
    public function allows(Channel $channel, User $user): bool
    {
        return match ($this) {
            self::Everyone => true,
            self::Admins => $channel->created_by === $user->id
                || ($channel->workspace->roleFor($user)?->canManageWorkspace() ?? false),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Iedereen in dit kanaal',
            self::Admins => 'Alleen beheerders en de kanaalmaker',
        };
    }

    /**
     * Shown next to the choice, because "admins" alone does not tell anyone
     * what changes for the rest of the channel.
     */
    public function description(): string
    {
        return match ($this) {
            self::Everyone => 'Een gewoon gesprek: elk lid kan berichten plaatsen.',
            self::Admins => 'Een zendkanaal: anderen kunnen wel reageren en in threads antwoorden.',
        };
    }
}
