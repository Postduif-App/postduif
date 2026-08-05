<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bring every workspace's owner role up to the full catalogue.
     *
     * The seed does this now — see SystemRole::defaultAbilities — but a seed
     * only ever runs for a workspace being made. Every workspace that already
     * exists kept the bag it was written with, which means the rights added
     * since are switched off for its owner too.
     *
     * That is not a cosmetic gap. Nobody may write into a role a right they do
     * not hold themselves, so a right the owner lacks is one nobody in that
     * workspace can turn on for anybody — the tickbox sits on the screen and
     * refuses every attempt to use it. This is what makes those rights
     * reachable at all.
     *
     * Only the seeded owner role, matched on its key and on is_system. A role a
     * workspace wrote for itself is that workspace's own business, including
     * one they happened to call "Eigenaar".
     */
    public function up(): void
    {
        Role::query()
            ->where('is_system', true)
            ->where('key', SystemRole::Owner->value)
            ->eachById(function (Role $role): void {
                $role->forceFill(['abilities' => WorkspaceAbility::values()])->save();
            });
    }

    /**
     * Deliberately nothing.
     *
     * Rolling this back would mean deciding which rights an owner "should" have
     * had, and the only honest answer is the one this replaced: a bag that
     * differs per workspace depending on when it was made. Taking rights away
     * from an owner on the strength of a guess is worse than leaving them.
     */
    public function down(): void {}
};
