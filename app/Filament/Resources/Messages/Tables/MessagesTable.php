<?php

namespace App\Filament\Resources\Messages\Tables;

use App\Actions\Chat\DeleteMessage;
use App\Models\Channel;
use App\Models\Message;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label('Bericht')
                    ->wrap()
                    ->limit(160)
                    ->description(fn (Message $record): string => $record->isDeleted() ? 'Verwijderd' : '')
                    /**
                     * Search goes through the generated tsvector column rather
                     * than a LIKE over every row; see Message::scopeMatching.
                     *
                     * Applied as a subquery rather than on the builder handed
                     * in: that one is typed as a builder over no model in
                     * particular, and a scope cannot be found on one of those.
                     * Naming the model here keeps the scope in one place.
                     */
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereIn('messages.id', Message::query()->matching($search)->select('id'))),

                TextColumn::make('author.name')
                    ->label('Auteur')
                    // A webhook has no member row behind it, so both the name
                    // and the handle below it come off the message itself.
                    ->state(fn (Message $record): string => $record->isFromBot()
                        ? (string) $record->bot_name
                        : $record->author->name)
                    ->description(fn (Message $record): string => $record->isFromBot()
                        ? 'Webhook'
                        : '@'.$record->author->username)
                    ->searchable(['name', 'username'])
                    ->sortable(),

                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->sortable(),

                TextColumn::make('channel.name')
                    ->label('Channel')
                    ->placeholder('DM')
                    ->sortable(),

                TextColumn::make('reply_count')
                    ->label('Antwoorden')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Geplaatst')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['author', 'workspace', 'channel']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('workspace')
                    ->label('Workspace')
                    ->relationship('workspace', 'name')
                    ->searchable()
                    ->preload(),

                /**
                 * A DM has no name, and a null label is not something Select
                 * accepts — so every channel gets one, falling back to its id.
                 * Not preloaded: the dropdown would otherwise hold every channel
                 * on the platform.
                 */
                SelectFilter::make('channel')
                    ->label('Channel')
                    ->relationship('channel', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Channel $record): string => $record->name ?? 'DM #'.$record->getKey())
                    ->searchable(),

                SelectFilter::make('author')
                    ->label('Auteur')
                    ->relationship('author', 'name')
                    ->searchable(),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Geplaatst vanaf'),
                        DatePicker::make('until')->label('Geplaatst tot'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),

                /**
                 * Deleting runs through the same action the chat app uses, so a
                 * message removed from here broadcasts MessageDeleted, drops its
                 * mentions and reactions, and leaves a tombstone when it has
                 * replies. A plain $record->delete() would do none of that.
                 */
                DeleteAction::make()
                    ->label('Verwijderen')
                    ->modalDescription('Het bericht verdwijnt direct bij iedereen die het channel open heeft.')
                    ->using(fn (Message $record) => app(DeleteMessage::class)->handle($record)),
            ]);
    }
}
