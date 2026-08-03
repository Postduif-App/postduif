<?php

namespace App\Actions\Marketing;

use App\Enums\WorkspaceRole;
use App\Features\WorkspaceFeature;

class BuildFeatureInventory
{
    /**
     * What this application can actually do, taken from the application.
     *
     * Derived rather than written out, and that is the whole point of it. A
     * marketing page maintained by hand starts as a description and becomes a
     * claim: the feature gets renamed, switched off by default, or dropped, and
     * the page goes on promising it. Here the labels and the sentences are the
     * same ones a beheerder reads in their own settings screen, because they
     * are read from the same classes.
     *
     * What it cannot derive is tone. Anything on the marketing site that is a
     * judgement — "the fastest", "the easiest" — has to be written by a person
     * and is not this action's business.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        return array_map(fn (string $feature): array => [
            'key' => $feature::key(),
            'label' => $feature::label(),
            'description' => $feature::description(),
            /*
             * Worth saying out loud on a public page rather than hiding: three
             * of these start switched off, and each one is off because it lets
             * something reach past the workspace — an AI client reading along,
             * a download link for the outside world, a store of other people's
             * passwords. "You decide, and nothing is decided for you" is a
             * stronger claim than any of the features individually.
             */
            'onByDefault' => $feature::default(),
        ], WorkspaceFeature::ALL);
    }

    /**
     * The roles somebody can hold, with what the code says each may do.
     *
     * Read off the enum for the same reason as above. The guest row is the
     * interesting one — it is the only role the application actively keeps out
     * of things, and every one of those answers lives in WorkspaceRole.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roles(): array
    {
        return array_map(fn (WorkspaceRole $role): array => [
            'value' => $role->value,
            'label' => $role->getLabel(),
            'canManageWorkspace' => $role->canManageWorkspace(),
            'canInviteMembers' => $role->canInviteMembers(),
            'canBrowseWorkspace' => $role->canBrowseWorkspace(),
            'canCreateChannels' => $role->canCreateChannels(),
            'canSendTransfers' => $role->canSendTransfers(),
        ], WorkspaceRole::cases());
    }
}
