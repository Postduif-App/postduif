<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelType: string implements HasLabel
{
    case Public = 'public';
    case Private = 'private';
    case Direct = 'dm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => __('enums.channel-type.getLabel.Public'),
            self::Private => __('enums.channel-type.getLabel.Private'),
            self::Direct => __('enums.channel-type.getLabel.Direct'),
        };
    }

    /**
     * Direct message channels have no name; they are labelled by their members.
     */
    public function hasName(): bool
    {
        return $this !== self::Direct;
    }
}
