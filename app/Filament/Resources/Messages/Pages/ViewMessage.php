<?php

namespace App\Filament\Resources\Messages\Pages;

use App\Actions\Chat\DeleteMessage;
use App\Filament\Resources\Messages\MessageResource;
use App\Models\Message;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMessage extends ViewRecord
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Verwijderen')
                ->modalDescription('Het bericht verdwijnt direct bij iedereen die het channel open heeft.')
                ->using(fn (Message $record) => app(DeleteMessage::class)->handle($record)),
        ];
    }
}
