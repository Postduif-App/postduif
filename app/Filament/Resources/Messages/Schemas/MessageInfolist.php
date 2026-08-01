<?php

namespace App\Filament\Resources\Messages\Schemas;

use App\Models\Message;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bericht')
                    ->schema([
                        TextEntry::make('body')
                            ->label('Inhoud'),
                    ])
                    ->columnSpanFull(),

                Section::make('Herkomst')
                    ->schema([
                        TextEntry::make('author.name')
                            ->label('Auteur')
                            ->state(fn (Message $record): string => $record->isFromBot()
                                ? (string) $record->bot_name
                                : $record->author->name)
                            ->helperText(fn (Message $record): string => $record->isFromBot()
                                ? 'Geplaatst via een webhook'
                                : '@'.$record->author->username),
                        TextEntry::make('workspace.name')->label('Workspace'),
                        TextEntry::make('channel.name')->label('Channel')->placeholder('DM'),
                        TextEntry::make('created_at')->label('Geplaatst')->dateTime('d-m-Y H:i'),
                        TextEntry::make('edited_at')->label('Bewerkt')->dateTime('d-m-Y H:i')->placeholder('Niet bewerkt'),
                        TextEntry::make('deleted_at')->label('Verwijderd')->dateTime('d-m-Y H:i')->placeholder('Nee'),
                        TextEntry::make('reply_count')->label('Antwoorden'),
                        TextEntry::make('parent_id')
                            ->label('Antwoord op')
                            ->placeholder('Geen — staat in het channel zelf'),
                        TextEntry::make('id')->label('ID')->copyable(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
