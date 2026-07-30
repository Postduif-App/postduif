<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('w/{workspace}')
    ->name('chat.')
    ->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('search', SearchController::class)->name('search');
        Route::get('c/{channel}', [ChatController::class, 'show'])->name('show');
        Route::post('c/{channel}/messages', [MessageController::class, 'store'])->name('messages.store');
    });
