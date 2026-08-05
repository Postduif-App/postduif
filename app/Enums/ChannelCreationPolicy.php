<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who may open a new channel in the workspace.
 *
 * Defaults to Everyone, the other way round from BroadcastMentionPolicy: a
 * channel nobody needed is a mess somebody can clean up later, while a mention
 * that pinged three hundred people cannot be taken back. Workspaces that would
 * rather keep the list tidy can close it, and closing it is the change that
 * needs to be deliberate.
 *
 * Guests are outside this question entirely — they may never open a channel,
 * whatever is chosen here. See SystemRole::canCreateChannels().
 */
enum ChannelCreationPolicy: string implements HasLabel
{
    case Everyone = 'everyone';
    case Admins = 'admins';

    public function allows(SystemRole $role): bool
    {
        return match ($this) {
            self::Everyone => true,
            self::Admins => $role->canManageWorkspace(),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => __('enums.channel-creation-policy.label.Everyone'),
            self::Admins => __('enums.channel-creation-policy.label.Admins'),
        };
    }
}
