<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who gets the list of everybody in the workspace, beside the conversation.
 *
 * Three answers rather than a switch, which is why this is a column and not a
 * Pennant feature — see WorkspaceFeature on where the line runs. The middle one
 * is the whole reason it exists: a workspace with customers in it may want its
 * own people to see who is around without handing the customer a staff
 * directory.
 *
 * Off by default. A panel that appears in every workspace the day it ships is
 * a layout change nobody asked for, and one of the three answers has to be
 * what happens when nobody chooses.
 */
enum MemberPanelVisibility: string implements HasLabel
{
    case Off = 'off';
    case Everyone = 'everyone';
    case Admins = 'admins';

    /**
     * Whether this role gets to see the panel.
     *
     * A guest never does, whatever is chosen here — they are in the workspace
     * for the channels they were invited to, and the membership list is not one
     * of those. The same reasoning as the tags in BuildChatShell.
     */
    public function allows(?WorkspaceRole $role): bool
    {
        if ($role === null || ! $role->canBrowseWorkspace()) {
            return false;
        }

        return match ($this) {
            self::Off => false,
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
            self::Off => __('enums.member-panel-visibility.label.Off'),
            self::Everyone => __('enums.member-panel-visibility.label.Everyone'),
            self::Admins => __('enums.member-panel-visibility.label.Admins'),
        };
    }
}
