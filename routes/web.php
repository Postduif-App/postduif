<?php

use App\Http\Controllers\SessionStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('session-status', SessionStatusController::class)->name('session.status');

// Order matters: settings claims /app/settings before chat.php registers the
// /app/{workspace} wildcard that would otherwise match it.
require __DIR__.'/settings.php';
require __DIR__.'/chat.php';

if (! app()->isProduction()) {
    require __DIR__.'/dev.php';
}
