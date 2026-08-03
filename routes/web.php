<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InviteLinkJoinController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PublicTransferController;
use App\Http\Controllers\SecretAnswerController;
use App\Http\Controllers\SecretFillController;
use App\Http\Controllers\SessionStatusController;
use Illuminate\Support\Facades\Route;

/**
 * The public site. Outside every auth group on purpose: this is the one part of
 * the application meant for people who have no account and may never get one.
 *
 * Note what it must survive — HandleInertiaRequests shares auth.workspace as
 * null for a signed-out visitor, and the workspace theme as an empty string. A
 * marketing layout that reached for either would work for the developer who is
 * always logged in and break for everybody else.
 */
Route::get('/', [MarketingController::class, 'home'])->name('home');

Route::get('session-status', SessionStatusController::class)->name('session.status');

/**
 * Deliberately outside the auth middleware: an invited guest has no account
 * yet, and the token in the link is what stands in for one until they do.
 */
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');

/**
 * The same door, for a link that names nobody. Outside auth for the same
 * reason, and throttled harder on the way in: a shareable link is a URL
 * anybody may try, so the accounts created through it are worth rate limiting.
 */
Route::get('join/{token}', [InviteLinkJoinController::class, 'show'])->name('invite-links.show');
Route::post('join/{token}', [InviteLinkJoinController::class, 'join'])
    ->middleware('throttle:10,1')
    ->name('invite-links.join');

/**
 * Files somebody put aside for you. Outside auth for the same reason as the two
 * above: the person following this link may have no account and may never want
 * one, and the token is what stands in for having been let in.
 *
 * Throttled harder than the pages above, and not only against guessing: these
 * responses are megabytes rather than kilobytes, so an unthrottled download
 * route is a way to spend somebody else's bandwidth by refreshing.
 */
Route::prefix('transfers/{token}')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', [PublicTransferController::class, 'show'])->name('transfers.show');

        /*
         * Answering the password. Throttled far harder than the rest of this
         * group: a password on a public URL is one anybody may sit and guess
         * at, and six tries a minute is the difference between a secret and a
         * formality.
         */
        Route::post('openen', [PublicTransferController::class, 'unlock'])
            ->middleware('throttle:6,1')
            ->name('transfers.unlock');

        // The zip before the single file: "zip" would otherwise be read as a
        // media id by the route below and 404 on its way to being a number.
        Route::get('zip', [PublicTransferController::class, 'downloadAll'])
            ->name('transfers.download-all');
        Route::get('files/{media}', [PublicTransferController::class, 'download'])
            ->name('transfers.download');
    });

/**
 * Answering a request for secrets.
 *
 * Behind auth, unlike a transfer link: the people who may answer are the people
 * who can see the channel it was asked in, and that is a question about an
 * account rather than about holding a token. Not under the workspace prefix
 * either — the answerer is often a guest, and this is the one screen they reach
 * without going through the chat.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('secrets/{secretRequest}', [SecretFillController::class, 'show'])
        ->name('secrets.show');
    Route::post('secrets/{secretRequest}', [SecretFillController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('secrets.fill');

    /*
     * The requester's own side. Its own screen rather than a section of the
     * form above, because the two are for different people — and one of them
     * must never see what the other typed.
     */
    Route::get('secrets/{secretRequest}/antwoorden', [SecretAnswerController::class, 'index'])
        ->name('secrets.answers');
    Route::post('secrets/{secretRequest}/antwoorden/{key}', [SecretAnswerController::class, 'reveal'])
        ->middleware('throttle:60,1')
        ->name('secrets.reveal');
});

/*
 * Somebody's face. Behind auth rather than on a public disk: this is a
 * photograph of a person, and the controller asks whether the viewer shares a
 * workspace with them.
 */
Route::get('avatars/users/{user}', AvatarController::class)
    ->middleware(['auth', 'verified'])
    ->name('avatars.user');

Route::get('avatars/workspaces/{workspace}', [AvatarController::class, 'workspace'])
    ->middleware(['auth', 'verified'])
    ->name('avatars.workspace');

// Order matters: settings claims /app/settings before chat.php registers the
// /app/{workspace} wildcard that would otherwise match it.
require __DIR__.'/settings.php';
require __DIR__.'/chat.php';

if (! app()->isProduction()) {
    require __DIR__.'/dev.php';
}
