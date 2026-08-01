<?php

namespace App\Enums;

use App\Models\User;
use App\Models\Workspace;
use Filament\Support\Contracts\HasLabel;

/**
 * Who may use a mention that reaches a whole channel at once.
 *
 * Defaults to Admins rather than Everyone: a mention that notifies everybody is
 * the one people complain about, and a workspace that wants it open can say so.
 * Opening it later annoys nobody; closing it after the fact means the habit is
 * already there.
 */
enum BroadcastMentionPolicy: string implements HasLabel
{
    case Everyone = 'everyone';
    case Admins = 'admins';
    case Nobody = 'nobody';

    public function allows(Workspace $workspace, User $user): bool
    {
        $role = $workspace->roleFor($user);

        if ($role === null) {
            return false;
        }

        return match ($this) {
            self::Everyone => true,
            self::Admins => $role->canManageWorkspace(),
            self::Nobody => false,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Iedereen in de workspace',
            self::Admins => 'Alleen beheerders en de eigenaar',
            self::Nobody => 'Niemand',
        };
    }
}
