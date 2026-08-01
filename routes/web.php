<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InviteLinkJoinController;
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
