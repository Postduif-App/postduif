<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document')
                    ->schema([
                        TextEntry::make('title')->label('Titel'),

                        /**
                         * The flattened text, not the document.
                         *
                         * A moderator opening this needs to read what it says —
                         * usually because somebody reported it — and body_text
                         * is exactly that, already stored. Rendering the block
                         * tree would mean a second renderer in PHP that has to
                         * keep up with the editor's plugins, and showing the
                         * raw JSON would be worse than showing nothing.
                         */
                        TextEntry::make('body_text')
                            ->label('Inhoud')
                            ->placeholder('Nog leeg')
                            ->prose(),
                    ])
                    ->columnSpanFull(),

                Section::make('Herkomst')
                    ->schema([
                        TextEntry::make('number')
                            ->label('Nummer')
                            ->state(fn (Document $record): string => '#'.$record->number),
                        TextEntry::make('workspace.name')->label('Workspace'),
                        TextEntry::make('channel.name')->label('Channel'),
                        TextEntry::make('creator.name')->label('Begonnen door'),
                        TextEntry::make('editor.name')
                            ->label('Laatst bewerkt door')
                            ->placeholder('Nog niemand'),
                        TextEntry::make('version')
                            ->label('Versie')
                            ->numeric(),
                        TextEntry::make('created_at')->label('Aangemaakt')->dateTime('d-m-Y H:i'),
                        TextEntry::make('updated_at')->label('Bijgewerkt')->dateTime('d-m-Y H:i'),
                        TextEntry::make('deleted_at')
                            ->label('Verwijderd')
                            ->dateTime('d-m-Y H:i')
                            ->placeholder('Nee'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
