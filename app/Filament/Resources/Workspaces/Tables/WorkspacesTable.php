<?php

namespace App\Filament\Resources\Workspaces\Tables;

use App\Enums\BroadcastMentionPolicy;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WorkspacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn ($record): string => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),

                TextColumn::make('owner.name')
                    ->label('Eigenaar')
                    ->searchable()
                    ->sortable(),

                /**
                 * Counts come from withCount() in the query below rather than
                 * from the relationships, so a page of workspaces stays two
                 * queries instead of two per row.
                 */
                TextColumn::make('members_count')
                    ->label('Leden')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('broadcast_mentions')
                    ->label('@channel')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('owner')->withCount(['members', 'channels']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('broadcast_mentions')
                    ->label('@channel-beleid')
                    ->options(BroadcastMentionPolicy::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
