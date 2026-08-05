<?php

namespace App\Filament\Resources\Channels\RelationManagers;

use App\Models\Channel;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is in a channel, in the admin panel.
 *
 * The counterpart of the channel's own member list in the chat UI, and the
 * moderator's way to put a guest into a channel or take them out again without
 * having to find somebody who is already in it. That matters most for private
 * channels, where the chat-side list is only reachable by its own members.
 *
 * Nothing here reads the conversation — ChannelResource::canView is what guards
 * this page, and it deliberately does not grant read access to messages.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Leden';

    /**
     * Filament makes relation managers read-only on a resource's view page.
     * That default suits a record you are only inspecting; membership is the
     * thing a moderator comes to this page to change.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Channel $channel */
        $channel = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (User $record): string => '@'.$record->username)
                    ->searchable(['name', 'username']),

                TextColumn::make('role')
                    ->label('Rol in de workspace')
                    ->state(fn (User $record): ?string => $channel->workspace->roleFor($record)?->name)
                    ->badge(),

                TextColumn::make('pivot.joined_at')
                    ->label('In het kanaal sinds')
                    ->dateTime('d-m-Y H:i'),
            ])
            ->defaultSort('name')
            ->headerActions([
                AttachAction::make()
                    ->label('Lid toevoegen')
                    ->recordSelectSearchColumns(['name', 'username', 'email'])
                    // Only people who already belong to the workspace: a
                    // channel is not a way into a workspace, and the picker
                    // would otherwise list every account on the platform.
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->whereIn('users.id', $channel->workspace->members()->select('users.id')))
                    ->mutateDataUsing(function (array $data): array {
                        $data['joined_at'] = now();

                        return $data;
                    })
                    // A DM's participants are what the conversation is; adding
                    // a third would change what both of them thought they were
                    // writing in. Same rule as ChannelPolicy::addMembers().
                    ->hidden(fn (): bool => $channel->isDirect()),
            ])
            ->recordActions([
                /**
                 * The creator is exempt for the same reason they cannot leave:
                 * a channel whose creator is gone has nobody who can manage its
                 * membership, so it would freeze as it is.
                 */
                DetachAction::make()
                    ->label('Verwijderen')
                    ->disabled(fn (User $record): bool => $channel->isDirect() || $channel->created_by === $record->id)
                    ->tooltip(fn (User $record): ?string => match (true) {
                        $channel->isDirect() => 'Een gesprek houdt twee deelnemers.',
                        $channel->created_by === $record->id => 'De maker van het kanaal kan er niet uit.',
                        default => null,
                    }),
            ]);
    }
}
