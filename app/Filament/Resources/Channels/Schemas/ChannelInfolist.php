<?php

namespace App\Filament\Resources\Channels\Schemas;

use App\Models\Channel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChannelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('workspace.name')->label('Workspace'),
                        TextEntry::make('name')->label('Naam')->placeholder('— (DM)'),
                        TextEntry::make('type')->label('Soort')->badge(),
                        TextEntry::make('topic')->label('Onderwerp')->placeholder('Geen'),
                        TextEntry::make('creator.name')->label('Aangemaakt door')->placeholder('Onbekend'),
                        TextEntry::make('created_at')->label('Aangemaakt')->dateTime('d-m-Y H:i'),
                        TextEntry::make('last_message_at')->label('Laatste bericht')->dateTime('d-m-Y H:i')->placeholder('Nog niets'),
                        TextEntry::make('archived_at')->label('Gearchiveerd')->dateTime('d-m-Y H:i')->placeholder('Nee'),
                        TextEntry::make('members_count')
                            ->label('Leden')
                            ->state(fn (Channel $record): int => $record->members()->count()),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
