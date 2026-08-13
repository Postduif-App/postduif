<?php

namespace App\Filament\Resources\Workspaces\Pages;

use App\Actions\Workspace\CreateHomeChannel;
use App\Actions\Workspace\EnsureOwnerIsMember;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Workspace;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    /**
     * A workspace made here starts with an owner who is in it and somewhere to
     * talk.
     *
     * afterCreate rather than a model event on the workspace: every test
     * fixture builds one too, and those want it empty. This is the place
     * somebody makes a workspace on purpose. See Workspace::booted() for where
     * the line between structure and content is drawn.
     *
     * The membership comes first — the channel action puts the owner in the
     * room it makes, and an owner in a channel of a workspace they do not
     * belong to is the more confusing half of the same bug.
     */
    protected function afterCreate(): void
    {
        $workspace = $this->getRecord();

        if (! $workspace instanceof Workspace) {
            throw new RuntimeException('This page creates workspaces and has just created something else.');
        }

        app(EnsureOwnerIsMember::class)->handle($workspace);
        app(CreateHomeChannel::class)->handle($workspace);
    }
}
