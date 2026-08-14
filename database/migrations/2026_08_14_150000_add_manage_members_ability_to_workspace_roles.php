<?php

use App\Enums\WorkspaceAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hand "leden beheren" to every role that could already do it.
     *
     * Shaped differently from the migrations that added a right before it, and
     * on purpose. Those introduced something nobody could do yet, so they
     * seeded the built-in roles from the enum and left a workspace's own roles
     * untouched — adding to a decision somebody sat down and made would be a
     * migration overruling a person.
     *
     * This one takes something out of an existing right rather than adding a
     * new one. Administering the ledenlijst used to follow from
     * ManageWorkspace; from now on it is asked for separately. So the question
     * is not "who should get this" but "who has it today", and the answer is
     * every role holding ManageWorkspace — the invented ones included.
     * Skipping those would mean a workspace that wrote itself a beheerder role
     * loses its member screen the morning this deploys, which is the same
     * overruling in the other direction.
     *
     * Idempotent: a role that already lists it is written back unchanged.
     */
    public function up(): void
    {
        DB::table('workspace_roles')
            ->orderBy('id')
            ->chunkById(100, function ($roles): void {
                foreach ($roles as $role) {
                    $held = $this->held($role);

                    if (! in_array(WorkspaceAbility::ManageWorkspace->value, $held, true)) {
                        continue;
                    }

                    $this->write($role, array_values(array_unique([
                        ...$held,
                        WorkspaceAbility::ManageMembers->value,
                    ])));
                }
            });
    }

    /** Take it back off every role — it came from here, and manage() covers it again. */
    public function down(): void
    {
        DB::table('workspace_roles')->orderBy('id')->chunkById(100, function ($roles): void {
            foreach ($roles as $role) {
                $this->write($role, array_values(array_diff(
                    $this->held($role),
                    [WorkspaceAbility::ManageMembers->value],
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
