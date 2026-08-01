<?php

namespace App\Filament\Resources\Attachments\Tables;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AttachmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('file_name')
                    ->label('Bestand')
                    ->description(fn (Media $record): string => (string) $record->mime_type)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('size')
                    ->label('Grootte')
                    // Bytes as somebody reads them, and a running total under
                    // the column: with a workspace filter on, that total is the
                    // answer to "where is the space going".
                    ->formatStateUsing(fn (int $state): string => self::readable($state))
                    ->summarize(
                        Sum::make()
                            ->label('Totaal')
                            ->formatStateUsing(fn (?int $state): string => self::readable((int) $state))
                    )
                    ->sortable(),

                TextColumn::make('model.workspace.name')
                    ->label('Workspace')
                    ->placeholder('—'),

                TextColumn::make('model.channel.name')
                    ->label('Channel')
                    ->placeholder('DM'),

                TextColumn::make('model.author.name')
                    ->label('Gedeeld door')
                    ->placeholder('Bot'),

                TextColumn::make('created_at')
                    ->label('Gedeeld op')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            /*
             * Only what hangs on a message. The media table is shared by every
             * model that keeps files, so without this the list would grow a
             * second kind of row the moment anything else starts using it.
             *
             * morphWith rather than a plain with(): the relation is polymorphic,
             * and naming the target is what lets it eager-load the message's own
             * workspace, channel and author instead of asking per row.
             */
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('model_type', (new Message)->getMorphClass())
                ->with(['model' => fn ($morphTo) => $morphTo->morphWith([
                    Message::class => ['workspace', 'channel', 'author'],
                ])]))
            ->defaultSort('created_at', 'desc')
            ->filters([
                /*
                 * Both filters reach through a polymorphic relation, which
                 * Filament's relationship() helper cannot walk — it expects a
                 * plain belongsTo. whereHasMorph names the target model, which
                 * is exactly the thing a morphTo leaves open.
                 */
                SelectFilter::make('workspace')
                    ->label('Workspace')
                    ->options(fn (): array => Workspace::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['value'] ?? null, fn (Builder $query, $id) => $query
                            ->whereHasMorph('model', Message::class, fn (Builder $query) => $query
                                ->where('workspace_id', $id)))),

                SelectFilter::make('channel')
                    ->label('Channel')
                    ->options(fn (): array => Channel::orderBy('name')
                        ->limit(100)
                        ->get()
                        ->mapWithKeys(fn (Channel $channel): array => [
                            $channel->getKey() => $channel->name ?? 'DM #'.$channel->getKey(),
                        ])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['value'] ?? null, fn (Builder $query, $id) => $query
                            ->whereHasMorph('model', Message::class, fn (Builder $query) => $query
                                ->where('channel_id', $id)))),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Gedeeld vanaf'),
                        DatePicker::make('until')->label('Gedeeld tot'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ]);
    }

    /** Bytes as somebody reads them: "1,4 MB", not "1468006". */
    private static function readable(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $kb = $bytes / 1024;

        return $kb < 1024
            ? round($kb).' KB'
            : number_format($kb / 1024, 1, ',', '.').' MB';
    }
}
