<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give the roles that already exist an answer about stepping into somebody
     * else's account.
     *
     * The same job the migrations before it did, and necessary for the same
     * reason: a new ability arrives switched off for everybody, because the bag
     * on a role only holds what somebody put in it. Without this the right
     * would exist as a tickbox nobody could ever use — nobody may hand out what
     * they do not hold themselves, so a right that no role holds is a right no
     * role can ever be given.
     *
     * Which in practice means the owner and nobody else, because that is what
     * the enum says for a fresh workspace. An administrator is not given it
     * with the job; see SystemRole::defaultAbilities.
     *
     * Only the built-in roles are touched. A role a workspace invented for
     * itself is left exactly as it is: somebody sat down and decided what it may
     * do, and a migration that quietly adds to that decision is a migration that
     * overrules a person — the more so for a right that reads other people's
     * private messages.
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

                    if (! in_array(WorkspaceAbility::ImpersonateMembers, $system->defaultAbilities(), true)) {
                        continue;
                    }

                    $this->write($role, array_values(array_unique([
                        ...$this->held($role),
                        WorkspaceAbility::ImpersonateMembers->value,
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
                    [WorkspaceAbility::ImpersonateMembers->value],
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
