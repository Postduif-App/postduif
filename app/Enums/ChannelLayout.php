<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a channel reads, which is a separate question from who may see it.
 *
 * Deliberately not a fourth ChannelType: that enum answers "who can get in" —
 * public, private, or a conversation between two people — and it is what the
 * visibility switch in the channel settings writes. Folding a layout into it
 * would make every feed implicitly public, when a company's internal news is
 * exactly the kind of thing that belongs in a private channel.
 */
enum ChannelLayout: string implements HasLabel
{
    case Chat = 'chat';
    case Feed = 'feed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Chat => __('enums.channel-layout.getLabel.Chat'),
            self::Feed => __('enums.channel-layout.getLabel.Feed'),
        };
    }

    public function isFeed(): bool
    {
        return $this === self::Feed;
    }

    /**
     * Shown under the label where the layout is chosen: the name alone does not
     * say what changes, and the difference is what the choice is about.
     */
    public function description(): string
    {
        return match ($this) {
            self::Chat => __('enums.channel-layout.description.Chat'),
            self::Feed => __('enums.channel-layout.description.Feed'),
        };
    }
}
