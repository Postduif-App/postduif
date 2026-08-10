<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Channel;
use App\Models\Document;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Titel')
                    ->wrap()
                    ->limit(120)
                    ->searchable()
                    ->sortable(),

                /**
                 * A line of the document, so the list can be scanned for what a
                 * document is actually about rather than what it was named.
                 *
                 * Searchable, and that is the useful part: it is the same column
                 * the workspace search reads, so a moderator hunting a phrase
                 * across every workspace finds it here too.
                 */
                TextColumn::make('body_text')
                    ->label('Inhoud')
                    ->limit(80)
                    ->placeholder('Nog leeg')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->sortable(),

                TextColumn::make('channel.name')
                    ->label('Channel')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Begonnen door')
                    ->searchable(),

                TextColumn::make('editor.name')
                    ->label('Laatst bewerkt door')
                    ->placeholder('Nog niemand')
                    ->searchable(),

                TextColumn::make('updated_at')
                    ->label('Bijgewerkt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'workspace', 'channel', 'creator', 'editor',
            ]))
            // By what was last worked on: the documents that are alive are the
            // ones anybody has a reason to look at.
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('workspace')
                    ->label('Workspace')
                    ->relationship('workspace', 'name')
                    ->searchable()
                    ->preload(),

                // Not preloaded: the dropdown would otherwise hold every channel
                // on the platform.
                SelectFilter::make('channel')
                    ->label('Channel')
                    ->relationship('channel', 'name')
                    ->getOptionLabelFromRecordUsing(
                        fn (Channel $record): string => $record->name ?? 'DM #'.$record->getKey()
                    )
                    ->searchable(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),

                /**
                 * Soft, so a document removed on a report can come back when the
                 * report turns out to be wrong. There is no other copy of a
                 * document anywhere — it is not a message somebody quoted, it is
                 * the only version of a thing a channel maintained for months.
                 */
                DeleteAction::make(),
                RestoreAction::make(),

                /**
                 * The real one, kept behind the trash filter: sometimes what has
                 * to go has to actually go.
                 */
                ForceDeleteAction::make(),
            ]);
    }
}
