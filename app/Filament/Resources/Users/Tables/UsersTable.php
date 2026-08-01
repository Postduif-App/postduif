<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ToggleAdminAction;
use App\Filament\Resources\Users\Actions\ToggleSuspendedAction;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (User $record): string => '@'.$record->username)
                    ->searchable(['name', 'username'])
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable(),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('admin_at')
                    ->label('Moderator')
                    ->boolean()
                    ->sortable(),

                /**
                 * Not boolean(): a green tick next to "geschorst" would read as
                 * good news. Suspended shows a red bar, everyone else nothing
                 * worth looking at.
                 */
                IconColumn::make('suspended_at')
                    ->label('Geschorst')
                    ->boolean()
                    ->trueIcon('heroicon-m-no-symbol')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-m-minus-small')
                    ->falseColor('gray')
                    ->sortable(),

                TextColumn::make('workspaces_count')
                    ->label('Workspaces')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('messages_count')
                    ->label('Berichten')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Aangemeld')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['workspaces', 'messages']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('admin_at')
                    ->label('Alleen moderators')
                    ->query(fn (Builder $query) => $query->whereNotNull('admin_at')),

                Filter::make('suspended')
                    ->label('Alleen geschorst')
                    ->query(fn (Builder $query) => $query->whereNotNull('suspended_at')),

                Filter::make('unverified')
                    ->label('E-mail niet bevestigd')
                    ->query(fn (Builder $query) => $query->whereNull('email_verified_at')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ToggleAdminAction::make(),
                    ToggleSuspendedAction::make(),
                ]),
            ]);
    }
}
