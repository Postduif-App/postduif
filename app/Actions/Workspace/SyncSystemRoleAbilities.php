<?php

namespace App\Actions\Workspace;

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Role;

/**
 * Bring the roles a workspace was given at birth back in line with the
 * catalogue.
 *
 * A right added to WorkspaceAbility arrives switched off for everybody: the bag
 * on a role only holds what somebody once put in it, and no seed runs again for
 * a workspace that already exists. For every role but one that is the right
 * default — a new right is nobody's until it is handed out.
 *
 * For the owner it is a dead end. Nobody may write into a role a right they do
 * not hold themselves (see Role::isWithin), so a right the owner lacks is one
 * that cannot be turned on for anybody, by anybody, ever: the tickbox sits on
 * the settings screen and refuses every attempt to use it. Somebody has to be
 * the ceiling, and SystemRole::defaultAbilities says that is the owner.
 *
 * Which is why this exists as a command rather than as yet another data
 * migration per right. It is idempotent, so it can be run after every deploy.
 */
class SyncSystemRoleAbilities
{
    /**
     * @param  bool  $includeOtherSystemRoles  Also top up administrator, member
     *                                         and guest from their defaults.
     *                                         Additive, and it overrules
     *                                         nobody's removals — see below.
     * @param  bool  $dryRun  Work out what would change and write nothing.
     * @return array{owners: int, others: int} Roles that changed, or would have.
     */
    public function handle(bool $includeOtherSystemRoles = false, bool $dryRun = false): array
    {
        $changed = ['owners' => 0, 'others' => 0];

        Role::query()
            ->where('is_system', true)
            ->eachById(function (Role $role) use ($includeOtherSystemRoles, $dryRun, &$changed): void {
                $system = SystemRole::tryFrom($role->key);

                if ($system === null) {
                    return;
                }

                $isOwner = $system === SystemRole::Owner;

                if (! $isOwner && ! $includeOtherSystemRoles) {
                    return;
                }

                $wanted = $isOwner
                    ? $this->everything()
                    : $this->toppedUp($role, $system);

                if ($wanted === $role->abilities) {
                    return;
                }

                $changed[$isOwner ? 'owners' : 'others']++;

                if (! $dryRun) {
                    $role->forceFill(['abilities' => $wanted])->save();
                }
            });

        return $changed;
    }

    /**
     * The whole catalogue, in the order the enum lists it.
     *
     * Written rather than merged, so a value left behind by a right that has
     * since been taken out of the application does not stay on the owner
     * forever. The owner is defined as "everything there is", and that is the
     * one role where the definition may overwrite what is stored.
     *
     * @return list<string>
     */
    private function everything(): array
    {
        return WorkspaceAbility::values();
    }

    /**
     * What the seed would have given this role, added to what it already holds.
     *
     * Additive on purpose. A workspace that switched InviteMembers off for its
     * administrators made a decision, and a command that quietly puts it back
     * every deploy is a command that overrules a person once a week. The cost
     * is that this cannot tell "never had it" apart from "had it and took it
     * away" — which is why it is behind a flag and not the default.
     *
     * @return list<string>
     */
    private function toppedUp(Role $role, SystemRole $system): array
    {
        $defaults = array_map(
            fn (WorkspaceAbility $ability): string => $ability->value,
            $system->defaultAbilities(),
        );

        $held = $role->abilities;

        /*
         * Sorted by the catalogue rather than appended, so two roles holding
         * the same rights hold them in the same order — the comparison above
         * is what decides whether a row is written at all.
         */
        return array_values(array_filter(
            WorkspaceAbility::values(),
            fn (string $ability): bool => in_array($ability, $held, true)
                || in_array($ability, $defaults, true),
        ));
    }
}
