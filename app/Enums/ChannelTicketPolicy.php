<?php

namespace App\Enums;

use App\Models\Channel;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a channel keeps tickets, and who may open one.
 *
 * Off by default. Most channels are a conversation and nothing else, and a
 * Tickets tab that is empty everywhere teaches people to stop looking at it —
 * which is exactly the habit you cannot afford in the channels that do use it.
 */
enum ChannelTicketPolicy: string implements HasLabel
{
    case Disabled = 'disabled';

    /**
     * The customer channel case, and the reason this setting exists: whoever is
     * in the channel can raise something, guests included.
     */
    case Everyone = 'everyone';

    /**
     * An internal board in a channel that happens to have guests in it. They
     * still read along — a ticket they may not see would be a ticket about them
     * behind their back — but they do not get to add to it.
     */
    case Members = 'members';

    public function isEnabled(): bool
    {
        return $this !== self::Disabled;
    }

    /**
     * Membership is checked before this runs; this only narrows it further.
     */
    public function allowsOpening(Channel $channel, User $user): bool
    {
        return match ($this) {
            self::Disabled => false,
            self::Everyone => true,
            self::Members => ! $channel->workspace->isExternal($user),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Disabled => __('enums.channel-ticket-policy.label.Disabled'),
            self::Everyone => __('enums.channel-ticket-policy.label.Everyone'),
            self::Members => __('enums.channel-ticket-policy.label.Members'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Disabled => __('enums.channel-ticket-policy.description.Disabled'),
            self::Everyone => __('enums.channel-ticket-policy.description.Everyone'),
            self::Members => __('enums.channel-ticket-policy.description.Members'),
        };
    }
}
