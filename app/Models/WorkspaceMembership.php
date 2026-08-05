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
 * @property string $role
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
     * Keep the pointer in step with the string while both exist.
     *
     * Every place that adds somebody to a workspace writes a role name — an
     * invitation, a link, a seeder, the admin panel. Rather than visit all of
     * them twice, the row fills in its own pointer here: once while the string
     * is still the truth, and again after the reads have moved over, when the
     * string is what gets dropped.
     *
     * firstWhere rather than a join: this runs once per person joining a
     * workspace, which is not a rate anything needs optimising for.
     */
    protected static function booted(): void
    {
        static::saving(function (self $membership): void {
            /*
             * Whenever the string moves, and not only when the pointer is
             * empty. Changing somebody's role writes the new name over the old
             * one, and a pointer that only filled itself in once would go on
             * naming the role they used to have.
             */
            if ($membership->exists && ! $membership->isDirty('role')) {
                return;
            }

            /*
             * Read off the raw attributes rather than through the property.
             * There is a role() method on this class, so asking for ->role on a
             * row that has none sends Eloquent looking for a relationship by
             * that name — and plenty of callers add somebody without naming a
             * role at all, leaning on the column's own default.
             *
             * Which is why the fallback is that same default, spelled out here:
             * the two have to agree, and a row inserted without a role would
             * otherwise get no pointer.
             */
            $key = $membership->getAttributes()['role'] ?? SystemRole::Member->value;

            $membership->workspace_role_id = Role::query()
                ->where('workspace_id', $membership->getAttributes()['workspace_id'] ?? null)
                ->where('key', $key)
                ->value('id');
        });
    }

    /** @return BelongsTo<Role, $this> */
    public function workspaceRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'workspace_role_id');
    }

    /** The role as the enum, rather than as the string in the column. */
    public function role(): SystemRole
    {
        return SystemRole::from($this->role);
    }
}
