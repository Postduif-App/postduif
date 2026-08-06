<?php

namespace App\Filament\Resources\Workspaces\RelationManagers;

use App\Models\Workspace;
use RuntimeException;

/**
 * The owner record of a relation manager mounted on WorkspaceResource.
 *
 * Filament promises no more than a Model, which is all a base class can know.
 * These managers are only ever reached from a workspace's own page, and the
 * callbacks below them ask a workspace things a Model cannot answer — its
 * channels, its roles, who owns it. Saying that once here is what lets them.
 */
trait InteractsWithWorkspace
{
    protected function workspace(): Workspace
    {
        $workspace = $this->getOwnerRecord();

        if (! $workspace instanceof Workspace) {
            throw new RuntimeException('This relation manager is mounted on something other than a workspace.');
        }

        return $workspace;
    }
}
