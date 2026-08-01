<?php

use App\Http\Controllers\WebhookMessageController;
use Illuminate\Support\Facades\Route;

/**
 * The one stateless corner of the application.
 *
 * Everything else lives behind a session in routes/chat.php. These routes are
 * called by machines, so they carry their own credential and must stay out of
 * the web middleware group — no CSRF token to send, no Inertia response to
 * misread, no suspended-account redirect to follow.
 *
 * The token sits in the path because that is what the tools pointing at this
 * expect. It will therefore turn up in access logs, which is the reason a
 * webhook can be revoked and re-minted without being recreated.
 */
Route::post('/webhooks/{token}', WebhookMessageController::class)
    ->middleware('throttle:webhook')
    ->name('webhooks.messages.store');
