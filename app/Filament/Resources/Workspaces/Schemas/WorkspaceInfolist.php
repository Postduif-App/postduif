<?php

namespace App\Filament\Resources\Workspaces\Schemas;

use App\Models\Workspace;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkspaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('name')->label('Naam'),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('owner.name')
                            ->label('Eigenaar')
                            ->helperText(fn (Workspace $record): string => $record->owner->email),
                        TextEntry::make('broadcast_mentions')
                            ->label('Wie mag @channel gebruiken')
                            ->badge(),
                        TextEntry::make('blocked_words')
                            ->label('Verboden woorden')
                            ->badge()
                            ->placeholder('Geen'),
                        TextEntry::make('members_count')
                            ->label('Leden')
                            ->state(fn (Workspace $record): int => $record->members()->count()),
                        TextEntry::make('created_at')
                            ->label('Aangemaakt')
                            ->dateTime('d-m-Y H:i'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
