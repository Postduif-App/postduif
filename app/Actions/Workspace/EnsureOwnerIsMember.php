<?php

namespace App\Actions\Workspace;

use App\Enums\SystemRole;
use App\Models\Workspace;

/**
 * The owner of a workspace is in it.
 *
 * Obvious enough that it went unwritten, and untrue of every workspace the
 * admin panel made: the form sets owner_id, which is a column on the workspace
 * and not a membership. The result was an owner who owned a place they were
 * not a member of — no policy would let them in, and the member list did not
 * show them. Their own first channel had them in it, which made it look like
 * it had worked.
 *
 * Idempotent, because it runs both when a workspace is made and when its owner
 * is handed to somebody else, and the second frequently changes nothing.
 */
class EnsureOwnerIsMember
{
    public function handle(Workspace $workspace): void
    {
        $owner = $workspace->owner;

        if ($owner === null || $workspace->hasMember($owner)) {
            return;
        }

        $workspace->members()->attach($owner->id, [
            'workspace_role_id' => $workspace->roles()
                ->where('key', SystemRole::Owner->value)
                ->value('id'),
            'joined_at' => now(),
        ]);
    }
}
