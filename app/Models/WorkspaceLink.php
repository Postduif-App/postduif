<?php

namespace App\Models;

use Database\Factories\WorkspaceLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A button in the workspace menu, with a link behind it.
 *
 * The workspace-wide counterpart to ChannelLink. It only ever opens a URL —
 * starting a workflow is something the bar above a conversation offers, because
 * a workflow started from there has a channel and a message to read, and one
 * started from a sidebar menu would have neither.
 *
 * Who sees it is a set of roles rather than a flag, and the empty set means
 * nobody. See the workspace_link_role migration for why that direction.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $label
 * @property string $url
 * @property string|null $emoji
 * @property int $position
 */
#[Fillable(['workspace_id', 'label', 'url', 'emoji', 'position'])]
class WorkspaceLink extends Model
{
    /** @use HasFactory<WorkspaceLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The roles this button is drawn for.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'workspace_link_role', 'workspace_link_id', 'workspace_role_id');
    }

    /**
     * Whether somebody holding this role is shown it.
     *
     * Takes the role rather than the user, because the caller already has it:
     * Workspace::roleFor remembers the answer for the length of the request,
     * and a link asking per row would undo that. Null — somebody with no role
     * here at all, such as a guest from another workspace's shared channel —
     * is nobody, and nobody sees anything.
     *
     * Reads the loaded relation when there is one. Lazy loading is off, so the
     * alternative for a list of ten links is an exception rather than ten
     * queries, but the shell loads them in one go either way.
     */
    public function isVisibleTo(?Role $role): bool
    {
        if ($role === null) {
            return false;
        }

        return $this->roles->contains('id', $role->id);
    }

    /**
     * The order the menu draws them in.
     *
     * The id breaks the tie rather than leaving it to the database — the same
     * reason as ChannelLink::scopeInOrder: two rows can share a position while
     * one of them is being moved, and a menu that swaps them between page loads
     * reads as a bug.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
