<?php

namespace App\Filament\Resources\Workspaces\Pages;

use App\Actions\Workspace\EnsureOwnerIsMember;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Workspace;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkspace extends EditRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Handing the workspace to somebody else makes them a member of it.
     *
     * The owner select happily points at anybody on the platform, which is
     * what makes it useful — the new owner is often somebody who was not in
     * there yet. Leaving them outside would set owner_id to a person no policy
     * would let through the door.
     *
     * The old owner is left where they are: they keep their membership and
     * whatever role the moderator gives them next. Quietly removing somebody
     * from a workspace is not what "change the owner" asks for.
     */
    protected function afterSave(): void
    {
        $workspace = $this->getRecord();

        if ($workspace instanceof Workspace) {
            app(EnsureOwnerIsMember::class)->handle($workspace);
        }
    }
}
