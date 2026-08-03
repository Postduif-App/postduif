<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InviteLinkJoinController;
use App\Http\Controllers\SecretAnswerController;
use App\Http\Controllers\SecretFillController;
use App\Http\Controllers\SessionStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

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
