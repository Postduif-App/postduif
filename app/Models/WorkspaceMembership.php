<?php

namespace App\Models;

use App\Enums\SystemRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int|null $workspace_role_id
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

    /**
     * A membership always points at a role.
     *
     * Plenty of callers add somebody without naming one — an attach with
     * nothing but joined_at — and a membership with no role is one no policy
     * can answer for: every permission check starts by asking which role this
     * is, and null is not an answer any of them have.
     *
     * So the default is filled in here rather than by the database, because a
     * column default cannot look up which row is this workspace's member role.
     */
    protected static function booted(): void
    {
        static::saving(function (self $membership): void {
            if ($membership->workspace_role_id !== null) {
                return;
            }

            /*
             * Off the raw attributes: on an unsaved pivot the relation is not
             * loaded, and asking through the property would send Eloquent
             * looking for a relationship rather than a column.
             */
            $membership->workspace_role_id = Role::query()
                ->where('workspace_id', $membership->getAttributes()['workspace_id'] ?? null)
                ->where('key', SystemRole::Member->value)
                ->value('id');
        });
    }

    /** @return BelongsTo<Role, $this> */
    public function workspaceRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'workspace_role_id');
    }
}
