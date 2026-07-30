<?php

namespace App\Enums;

enum ChannelType: string
{
    case Public = 'public';
    case Private = 'private';
    case Direct = 'dm';

    /**
     * Direct message channels have no name; they are labelled by their members.
     */
    public function hasName(): bool
    {
        return $this !== self::Direct;
    }
}
