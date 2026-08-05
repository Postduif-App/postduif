<?php

namespace App\Filament\Resources\Workspaces\Pages;

use App\Actions\Workspace\CreateHomeChannel;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    /**
     * A workspace made here starts with somewhere to talk.
     *
     * afterCreate rather than a model event on the workspace: every test
     * fixture builds one too, and those want it empty. This is the place
     * somebody makes a workspace on purpose. See Workspace::booted() for where
     * the line between structure and content is drawn.
     */
    protected function afterCreate(): void
    {
        app(CreateHomeChannel::class)->handle($this->getRecord());
    }
}
