<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionName;
use Filament\Notifications\Notification;

/**
 * Granting or revoking platform moderation, as an explicit confirmed action
 * rather than a checkbox in the edit form.
 *
 * Two reasons. It keeps admin_at out of the user's fillable columns, so no form
 * or request can ever set it by accident. And it makes each change a deliberate
 * step with its own confirmation, which is what handing someone the keys to
 * every workspace deserves.
 */
#[ActionName('toggleAdmin')]
class ToggleAdminAction
{
    public static function make(): Action
    {
        return Action::make('toggleAdmin')
            ->label(fn (User $record): string => $record->isAdmin() ? 'Rechten intrekken' : 'Moderator maken')
            ->icon(fn (User $record): string => $record->isAdmin() ? 'heroicon-m-shield-exclamation' : 'heroicon-m-shield-check')
            ->color(fn (User $record): string => $record->isAdmin() ? 'danger' : 'primary')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->isAdmin()
                ? 'Deze gebruiker verliest toegang tot het adminpanel.'
                : 'Deze gebruiker krijgt toegang tot het adminpanel en tot elke workspace op het platform.')
            ->authorize('toggleAdmin')
            ->action(function (User $record): void {
                $record->forceFill(['admin_at' => $record->isAdmin() ? null : now()])->save();

                Notification::make()
                    ->success()
                    ->title($record->isAdmin() ? 'Moderator toegevoegd' : 'Rechten ingetrokken')
                    ->send();
            });
    }
}
