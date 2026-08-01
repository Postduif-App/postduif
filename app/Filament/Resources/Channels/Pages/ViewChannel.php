<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Filament\Resources\Channels\ChannelResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChannel extends ViewRecord
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ToggleChannelArchivedAction::make(),
            EditAction::make(),
        ];
    }
}
