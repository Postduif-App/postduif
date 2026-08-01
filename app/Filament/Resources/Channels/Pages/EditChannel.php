<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Filament\Resources\Channels\ChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditChannel extends EditRecord
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ToggleChannelArchivedAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
