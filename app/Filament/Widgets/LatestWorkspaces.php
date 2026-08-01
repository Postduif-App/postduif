<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Workspace;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestWorkspaces extends TableWidget
{
    protected static ?string $heading = 'Laatst aangemaakte workspaces';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Workspace::query()
                ->with('owner')
                ->withCount(['members', 'channels']))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (Workspace $record): string => $record->slug),

                TextColumn::make('owner.name')
                    ->label('Eigenaar'),

                TextColumn::make('members_count')
                    ->label('Leden')
                    ->numeric(),

                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->numeric(),

                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i'),
            ])
            ->recordUrl(fn (Workspace $record): string => WorkspaceResource::getUrl('view', ['record' => $record]));
    }
}
