<?php

use App\Http\Controllers\Dev\QuickLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Development Routes
|--------------------------------------------------------------------------
|
| Only loaded outside production — see routes/web.php. Nothing in here may be
| relied upon by the application itself.
|
*/

Route::post('dev/login/{user}', QuickLoginController::class)
    ->middleware('guest')
    ->name('dev.login');
