<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What somebody is in a workspace, and what that lets them do.
 *
 * Named Role rather than WorkspaceRole even though the table is
 * workspace_roles: there is no other kind here, and a second class called
 * WorkspaceRole beside the enum of the same name would leave every reader
 * asking which one they were looking at. The enum kept its own name and is now
 * only the four this application ships with — see SystemRole.
 *
 * A role belongs to one workspace. Two workspaces that both call something
 * "Leverancier" have two rows and no relationship between them, which is the
 * point: one of them can change what it means without the other noticing.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property string $name
 * @property bool $is_external
 * @property bool $is_system
 * @property int $position
 * @property list<string> $abilities
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'is_external', 'is_system', 'position', 'abilities'])]
class Role extends Model
{
    protected $table = 'workspace_roles';

    /** @var array<string, mixed> */
    protected $attributes = [
        'abilities' => '[]',
        'is_external' => false,
        'is_system' => false,
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
            'abilities' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whether this role holds a particular right.
     *
     * The one question the policies ask. Anything not in the bag is a no,
     * including a right that was added to the catalogue after this row was
     * written — which is the safe direction: a new ability arrives switched off
     * for everybody until a workspace says otherwise.
     */
    public function allows(WorkspaceAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Every right it holds, as the catalogue knows them.
     *
     * Filtered through the enum rather than handed back raw, so a value left
     * behind by a right that has since been taken out of the application does
     * not turn up on a screen as a tickbox nothing enforces.
     *
     * @return Collection<int, WorkspaceAbility>
     */
    public function abilities(): Collection
    {
        return collect($this->abilities)
            ->map(fn (string $ability): ?WorkspaceAbility => WorkspaceAbility::tryFrom($ability))
            ->filter()
            ->values();
    }

    /**
     * Whether everything this role may do, that role may do too.
     *
     * The question behind the rule that keeps this feature from being a way to
     * promote yourself: nobody may hand out, or write into a role, a right they
     * do not hold themselves. Asked of the abilities rather than of some notion
     * of seniority, because roles here are a set and not a ladder.
     */
    public function isWithin(self $other): bool
    {
        return $this->abilities()->every(fn (WorkspaceAbility $ability): bool => $other->allows($ability));
    }

    /** The role this workspace ships with, by the key the enum spells. */
    public function isSystemRole(SystemRole $role): bool
    {
        return $this->is_system && $this->key === $role->value;
    }

    /**
     * The roles somebody may be given, in the order the screens list them.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
