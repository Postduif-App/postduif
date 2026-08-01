<?php

namespace App\Filament\Resources\Channels\Tables;

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Models\Channel;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Channel')
                    ->description(fn (Channel $record): ?string => $record->topic)
                    ->placeholder('— (DM)')
                    ->searchable(['name', 'slug', 'topic']),

                TextColumn::make('type')
                    ->label('Soort')
                    ->badge()
                    ->sortable(),

                TextColumn::make('posting_policy')
                    ->label('Posten')
                    ->badge()
                    ->sortable(),

                TextColumn::make('members_count')
                    ->label('Leden')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('messages_count')
                    ->label('Berichten')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('last_message_at')
                    ->label('Laatste bericht')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nog niets')
                    ->sortable(),

                TextColumn::make('archived_at')
                    ->label('Gearchiveerd')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nee')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('workspace')->withCount(['members', 'messages']))
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('workspace')
                    ->label('Workspace')
                    ->relationship('workspace', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label('Soort')
                    ->options(ChannelType::class),

                SelectFilter::make('posting_policy')
                    ->label('Wie mag posten')
                    ->options(ChannelPostingPolicy::class),

                Filter::make('archived')
                    ->label('Alleen gearchiveerd')
                    ->query(fn (Builder $query) => $query->whereNotNull('archived_at')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ToggleChannelArchivedAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
