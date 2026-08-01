<?php

namespace App\Concerns;

use App\Models\Workspace;
use Illuminate\Http\Request;

trait ResolvesCurrentWorkspace
{
    /**
     * The workspace the settings screens act on, and only if the member is
     * allowed to do the thing they are asking for.
     *
     * There is one workspace per member today; once there are several this is
     * the single place where the current one gets resolved instead.
     */
    protected function currentWorkspace(Request $request, string $ability = 'manage'): Workspace
    {
        $workspace = $request->user()->workspaces()->oldest('workspace_user.joined_at')->first();

        abort_if($workspace === null, 404);
        $this->authorize($ability, $workspace);

        return $workspace;
    }
}
