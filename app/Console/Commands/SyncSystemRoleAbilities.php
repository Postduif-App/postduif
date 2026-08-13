<?php

namespace App\Console\Commands;

use App\Actions\Workspace\SyncSystemRoleAbilities as Syncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Run this after a deploy that added a right to WorkspaceAbility.
 *
 * Without it the new right is unreachable rather than merely switched off: the
 * owner does not hold it either, and nobody may hand out a right they do not
 * hold. See SyncSystemRoleAbilities for why the owner is the ceiling.
 */
#[Signature('workspaces:sync-role-abilities {--system-roles : Ook beheerder, lid en gast bijwerken vanuit hun standaardrechten} {--dry-run : Alleen tonen wat er zou veranderen}')]
#[Description('Geef elke eigenaarsrol de rechten die er sinds haar aanmaak bij zijn gekomen')]
class SyncSystemRoleAbilities extends Command
{
    public function handle(Syncer $syncer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        ['owners' => $owners, 'others' => $others] = $syncer->handle(
            includeOtherSystemRoles: (bool) $this->option('system-roles'),
            dryRun: $dryRun,
        );

        if ($owners === 0 && $others === 0) {
            $this->info(__('console.role_abilities_in_sync'));

            return self::SUCCESS;
        }

        /*
         * Both lines whenever either moved, the zero included: "3 eigenaarsrollen,
         * 0 overige rollen" says the command looked at both and found nothing on
         * one side, where a single line leaves the reader guessing whether the
         * other half ran.
         */
        $this->info(trans_choice($dryRun
            ? 'console.role_abilities_owners_pending'
            : 'console.role_abilities_owners_synced', $owners));

        $this->info(trans_choice($dryRun
            ? 'console.role_abilities_others_pending'
            : 'console.role_abilities_others_synced', $others));

        return self::SUCCESS;
    }
}
