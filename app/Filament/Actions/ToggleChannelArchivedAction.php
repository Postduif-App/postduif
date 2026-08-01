<?php

namespace App\Filament\Actions;

use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\ActionName;

/**
 * Archiving is the softer moderation lever: the channel and its history stay
 * readable, but nobody can post in it any more. ChannelPolicy already treats
 * archived_at that way, and ChatController hides archived channels from the
 * sidebar, so this needs no new rules of its own.
 *
 * Shared between the channel resource and the workspace's channel list.
 */
#[ActionName('toggleArchived')]
class ToggleChannelArchivedAction
{
    public static function make(): Action
    {
        return Action::make('toggleArchived')
            ->label(fn (Channel $record): string => $record->archived_at === null ? 'Archiveren' : 'Heropenen')
            ->icon(fn (Channel $record): string => $record->archived_at === null
                ? 'heroicon-m-archive-box'
                : 'heroicon-m-archive-box-x-mark')
            ->color(fn (Channel $record): string => $record->archived_at === null ? 'warning' : 'gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Channel $record): string => $record->archived_at === null
                ? 'Niemand kan hierna nog posten in dit channel. De geschiedenis blijft leesbaar.'
                : 'Leden kunnen hierna weer posten in dit channel.')
            ->authorize('update')
            /**
             * forceFill, because archived_at is deliberately absent from the
             * channel's fillable columns — it is never set from user input.
             */
            ->action(fn (Channel $record) => $record->forceFill([
                'archived_at' => $record->archived_at === null ? now() : null,
            ])->save());
    }
}
