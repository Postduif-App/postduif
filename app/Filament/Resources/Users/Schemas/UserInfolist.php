<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gebruiker')
                    ->schema([
                        TextEntry::make('name')->label('Naam'),
                        TextEntry::make('username')->label('Gebruikersnaam')->prefix('@'),
                        TextEntry::make('email')->label('E-mail')->copyable(),
                        TextEntry::make('email_verified_at')
                            ->label('E-mail bevestigd')
                            ->dateTime('d-m-Y H:i')
                            ->placeholder('Nog niet'),
                        TextEntry::make('admin_at')
                            ->label('Moderator sinds')
                            ->dateTime('d-m-Y H:i')
                            ->placeholder('Geen moderator'),
                        TextEntry::make('created_at')
                            ->label('Aangemeld')
                            ->dateTime('d-m-Y H:i'),
                        TextEntry::make('suspended_at')
                            ->label('Geschorst sinds')
                            ->dateTime('d-m-Y H:i')
                            ->badge()
                            ->color('danger')
                            ->placeholder('Niet geschorst'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Workspaces')
                    ->schema([
                        TextEntry::make('workspaces.name')
                            ->label('Lid van')
                            ->badge()
                            ->placeholder('Nog geen workspace'),
                        TextEntry::make('messages_count')
                            ->label('Berichten geplaatst')
                            ->state(fn (User $record): int => $record->messages()->count()),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
