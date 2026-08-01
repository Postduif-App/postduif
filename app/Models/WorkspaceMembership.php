<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Somebody's place in a workspace.
 *
 * A class rather than the framework's bare Pivot so the columns on the join
 * table are something you can look up and something a type checker can see.
 * The role in particular is read all over the application, and "a string on an
 * untyped pivot" is how it ends up compared against a literal somewhere.
 *
 * @property string $role
 * @property string|null $display_name
 * @property Carbon|null $joined_at
 */
class WorkspaceMembership extends Pivot
{
    protected $table = 'workspace_user';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    /** The role as the enum, rather than as the string in the column. */
    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->role);
    }
}
