<?php

namespace App\Filament\Resources\Workspaces\RelationManagers;

use App\Enums\ChannelType;
use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Models\Channel;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Channels';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (Channel $record): ?string => $record->topic)
                    ->placeholder('— (DM)')
                    ->searchable(['name', 'slug', 'topic']),

                TextColumn::make('type')
                    ->label('Soort')
                    ->badge(),

                TextColumn::make('members_count')
                    ->label('Leden')
                    ->numeric(),

                TextColumn::make('messages_count')
                    ->label('Berichten')
                    ->numeric(),

                TextColumn::make('last_message_at')
                    ->label('Laatste bericht')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nog niets'),

                TextColumn::make('archived_at')
                    ->label('Gearchiveerd')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nee'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['members', 'messages']))
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Soort')
                    ->options(ChannelType::class),
            ])
            ->recordActions([
                ToggleChannelArchivedAction::make(),
                DeleteAction::make()->label('Verwijderen'),
            ]);
    }
}
