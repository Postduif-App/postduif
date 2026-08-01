<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\ToggleAdminAction;
use App\Filament\Resources\Users\Actions\ToggleSuspendedAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ToggleAdminAction::make(),
            ToggleSuspendedAction::make(),
            ViewAction::make(),
        ];
    }
}
