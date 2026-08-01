<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Models\Ticket;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->schema([
                        TextEntry::make('title')->label('Titel'),
                        TextEntry::make('body')->label('Omschrijving'),
                    ])
                    ->columnSpanFull(),

                Section::make('Behandeling')
                    ->schema([
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('priority')->label('Prioriteit')->badge(),
                        TextEntry::make('assignee.name')->label('Toegewezen aan')->placeholder('Niemand'),
                        TextEntry::make('due_at')->label('Streefdatum')->dateTime('d-m-Y H:i')->placeholder('Geen'),
                        TextEntry::make('first_responded_at')
                            ->label('Eerste reactie')
                            ->dateTime('d-m-Y H:i')
                            ->placeholder('Nog geen'),
                        TextEntry::make('closed_at')->label('Gesloten')->dateTime('d-m-Y H:i')->placeholder('Nee'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Herkomst')
                    ->schema([
                        TextEntry::make('number')->label('Nummer')->state(fn (Ticket $record): string => '#'.$record->number),
                        TextEntry::make('workspace.name')->label('Workspace'),
                        TextEntry::make('channel.name')->label('Channel'),
                        TextEntry::make('opener.name')->label('Aangemaakt door'),
                        TextEntry::make('created_at')->label('Aangemaakt')->dateTime('d-m-Y H:i'),
                        TextEntry::make('source_message_id')
                            ->label('Uit bericht')
                            ->placeholder('Los aangemaakt')
                            ->copyable(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
