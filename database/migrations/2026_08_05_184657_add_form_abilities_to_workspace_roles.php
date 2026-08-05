<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the roles that already exist an answer about forms.
     *
     * A new ability arrives switched off for everybody — the bag on a role only
     * holds what somebody put in it — so without this migration a workspace
     * that has been running for a year would have forms nobody could make, and
     * the only way back would be for an owner to go and tick two boxes they
     * were never told about.
     *
     * Only the built-in roles are touched, and only by the same rule the enum
     * uses for a fresh workspace. A role a workspace invented itself is left
     * exactly as it is: somebody sat down and decided what "Leverancier" may
     * do, and a migration that adds to that decision is a migration that
     * overrules it.
     */
    public function up(): void
    {
        DB::table('workspace_roles')
            ->where('is_system', true)
            ->orderBy('id')
            ->chunkById(100, function ($roles): void {
                foreach ($roles as $role) {
                    $system = SystemRole::tryFrom((string) $role->key);

                    if ($system === null) {
                        continue;
                    }

                    $granted = array_map(
                        fn (WorkspaceAbility $ability): string => $ability->value,
                        array_filter(
                            [WorkspaceAbility::CreateForms, WorkspaceAbility::ShareFormsPublicly],
                            fn (WorkspaceAbility $ability): bool => in_array($ability, $system->defaultAbilities(), true),
                        ),
                    );

                    $this->write($role, array_values(array_unique([...$this->held($role), ...$granted])));
                }
            });
    }

    /** Take both back off every role, invented ones included — they came from here. */
    public function down(): void
    {
        DB::table('workspace_roles')->orderBy('id')->chunkById(100, function ($roles): void {
            foreach ($roles as $role) {
                $this->write($role, array_values(array_diff($this->held($role), [
                    WorkspaceAbility::CreateForms->value,
                    WorkspaceAbility::ShareFormsPublicly->value,
                ])));
            }
        });
    }

    /** @param  list<string>  $abilities */
    private function write(object $role, array $abilities): void
    {
        DB::table('workspace_roles')
            ->where('id', $role->id)
            ->update(['abilities' => json_encode($abilities)]);
    }

    /** @return list<string> */
    private function held(object $role): array
    {
        $decoded = json_decode((string) $role->abilities, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
};
