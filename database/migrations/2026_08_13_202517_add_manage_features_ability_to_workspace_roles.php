<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the roles that already exist an answer about switching parts of the
     * product on and off.
     *
     * The same job the migrations before it did, and necessary for the same
     * reason: a new ability arrives switched off for everybody, because the bag
     * on a role only holds what somebody put in it. Without this the screen
     * would refuse the owner of every workspace that already exists — and no
     * administrator could hand the right out either, since nobody may grant
     * what they do not hold themselves.
     *
     * Which in practice means the owner and nobody else, because that is what
     * the enum says for a fresh workspace.
     *
     * Only the built-in roles are touched. A role a workspace invented for
     * itself is left exactly as it is: somebody sat down and decided what it may
     * do, and a migration that quietly adds to that decision is a migration that
     * overrules a person.
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

                    if (! in_array(WorkspaceAbility::ManageFeatures, $system->defaultAbilities(), true)) {
                        continue;
                    }

                    $this->write($role, array_values(array_unique([
                        ...$this->held($role),
                        WorkspaceAbility::ManageFeatures->value,
                    ])));
                }
            });
    }

    /** Take it back off every role, invented ones included — it came from here. */
    public function down(): void
    {
        DB::table('workspace_roles')->orderBy('id')->chunkById(100, function ($roles): void {
            foreach ($roles as $role) {
                $this->write($role, array_values(array_diff(
                    $this->held($role),
                    [WorkspaceAbility::ManageFeatures->value],
                )));
            }
        });
    }

    /**
     * @param  list<string>  $abilities
     */
    private function write(stdClass $role, array $abilities): void
    {
        DB::table('workspace_roles')
            ->where('id', $role->id)
            ->update(['abilities' => json_encode($abilities)]);
    }

    /**
     * @return list<string>
     */
    private function held(stdClass $role): array
    {
        $decoded = json_decode((string) $role->abilities, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
};
