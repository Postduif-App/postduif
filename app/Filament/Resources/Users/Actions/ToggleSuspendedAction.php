<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionName;
use Filament\Notifications\Notification;

/**
 * Suspending someone, or letting them back in.
 *
 * This is the moderation lever that takes the place of deleting a user:
 * everything they wrote and every workspace they belong to stays untouched, they
 * simply cannot get in. EnsureAccountIsNotSuspended does the enforcing, so
 * flipping the timestamp is all that happens here — including for members who
 * are signed in at this very moment, who are out on their next request.
 */
#[ActionName('toggleSuspended')]
class ToggleSuspendedAction
{
    public static function make(): Action
    {
        return Action::make('toggleSuspended')
            ->label(fn (User $record): string => $record->isSuspended() ? 'Schorsing opheffen' : 'Schorsen')
            ->icon(fn (User $record): string => $record->isSuspended()
                ? 'heroicon-m-lock-open'
                : 'heroicon-m-no-symbol')
            ->color(fn (User $record): string => $record->isSuspended() ? 'gray' : 'danger')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->isSuspended()
                ? 'Deze gebruiker kan hierna weer inloggen en meedoen.'
                : 'Deze gebruiker wordt direct uitgelogd en kan niet meer inloggen. Berichten en workspaces blijven staan.')
            ->authorize('toggleSuspended')
            /**
             * forceFill, because suspended_at is deliberately absent from the
             * user's fillable columns — it is never set from user input.
             */
            ->action(function (User $record): void {
                $record->forceFill([
                    'suspended_at' => $record->isSuspended() ? null : now(),
                ])->save();

                Notification::make()
                    ->success()
                    ->title($record->isSuspended() ? 'Gebruiker geschorst' : 'Schorsing opgeheven')
                    ->send();
            });
    }
}
