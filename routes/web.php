<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Order matters: settings claims /app/settings before chat.php registers the
// /app/{workspace} wildcard that would otherwise match it.
require __DIR__.'/settings.php';
require __DIR__.'/chat.php';
