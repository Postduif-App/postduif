<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TicketsTable
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

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (TicketStatus $state): string => match ($state) {
                        TicketStatus::Open => 'warning',
                        TicketStatus::InProgress => 'info',
                        TicketStatus::Waiting => 'gray',
                        TicketStatus::Resolved => 'success',
                        TicketStatus::Closed => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('priority')
                    ->label('Prioriteit')
                    ->badge()
                    ->color(fn (TicketPriority $state): string => match ($state) {
                        TicketPriority::Urgent => 'danger',
                        TicketPriority::High => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->sortable(),

                TextColumn::make('channel.name')
                    ->label('Channel')
                    ->sortable(),

                TextColumn::make('opener.name')
                    ->label('Aangemaakt door')
                    ->searchable(),

                TextColumn::make('assignee.name')
                    ->label('Toegewezen aan')
                    ->placeholder('Niemand')
                    ->searchable(),

                /**
                 * How long it took before anybody answered — the number that
                 * says whether a customer channel is being served, and the whole
                 * reason first_responded_at is recorded.
                 */
                TextColumn::make('first_responded_at')
                    ->label('Eerste reactie')
                    ->placeholder('Nog geen')
                    ->state(fn (Ticket $record): ?string => $record->first_responded_at
                        /*
                         * DIFF_ABSOLUTE, not true. The parameter takes one of
                         * Carbon's constants, and a bool coerces to 1 — which
                         * happens to be this constant, so the column read
                         * correctly by accident. Spelled out, it also says what
                         * it means: "3 uur", not "3 uur later".
                         */
                        ?->diffForHumans($record->created_at, short: true, syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'workspace', 'channel', 'opener', 'assignee',
            ]))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(TicketStatus::class)
                    ->multiple(),

                SelectFilter::make('priority')
                    ->label('Prioriteit')
                    ->options(TicketPriority::class)
                    ->multiple(),

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
                    ->getOptionLabelFromRecordUsing(fn (Channel $record): string => $record->name ?? 'DM #'.$record->getKey())
                    ->searchable(),

                /**
                 * The one question this cross-workspace view exists for: where
                 * is work piling up that nobody has answered?
                 */
                Filter::make('unanswered')
                    ->label('Nog geen reactie')
                    ->query(fn (Builder $query) => $query
                        // Through the model rather than on the builder handed
                        // in, which is typed over no model in particular — see
                        // MessagesTable for the same reason.
                        ->whereIn('tickets.id', Ticket::query()->open()->select('id'))
                        ->whereNull('first_responded_at')),

                Filter::make('overdue')
                    ->label('Over de streefdatum')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('tickets.id', Ticket::query()->open()->select('id'))
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now())),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
