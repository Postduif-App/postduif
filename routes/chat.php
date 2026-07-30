<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('app')->group(function () {
    Route::get('/', [ChatController::class, 'home'])->name('chat.home');

    /**
     * The workspace slug is a wildcard directly under /app, so it could swallow
     * /app/settings. Two things keep that from happening: settings.php is
     * registered first, and the pattern below refuses "settings" outright — so
     * a workspace can never claim the slug either.
     */
    Route::prefix('{workspace}')
        ->name('chat.')
        ->where(['workspace' => '(?!settings$)[a-z0-9][a-z0-9-]*'])
        ->group(function () {
            Route::get('/', [ChatController::class, 'index'])->name('index');
            Route::get('search', SearchController::class)->name('search');
            Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
            Route::get('c/{channel}', [ChatController::class, 'show'])->name('show');
            Route::post('c/{channel}/join', [ChannelController::class, 'join'])->name('channels.join');
            Route::get('c/{channel}/members', [ChannelMemberController::class, 'index'])->name('channels.members.index');
            Route::post('c/{channel}/members', [ChannelMemberController::class, 'store'])->name('channels.members.store');
            Route::delete('c/{channel}/members', [ChannelMemberController::class, 'destroy'])->name('channels.members.destroy');
            Route::delete('c/{channel}/members/{user}', [ChannelMemberController::class, 'remove'])->name('channels.members.remove');
            Route::post('c/{channel}/messages', [MessageController::class, 'store'])->name('messages.store');
        });
});
