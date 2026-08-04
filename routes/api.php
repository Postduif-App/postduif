<?php

use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\WebhookMessageController;
use App\Http\Controllers\WorkflowWebhookController;
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

/*
 * The same shape one door along: a URL that sets a workflow off instead of
 * posting a message. Its own route rather than a flag on the one above, because
 * the two resolve different rows and mean different things — and a single
 * endpoint that guessed which from the token would be one lookup away from
 * posting a payload into a channel nobody meant.
 *
 * Throttled harder than the message webhook. A workflow behind this can post in
 * several channels and add people to them, so an open-ended one is a bigger
 * lever than a single message.
 */
Route::post('/workflows/{token}', WorkflowWebhookController::class)
    ->middleware('throttle:workflow-webhook')
    ->name('workflows.webhook');

/*
 * The token API, versioned from the start because it is meant to be pointed at
 * by things nobody here controls — a script on somebody's laptop outlives any
 * assumption about the shape of this response.
 *
 * Behind a personal token that resolves to a member; every route below is about
 * that member and nobody else, which is why none of them carries an id.
 */
Route::prefix('v1')
    ->middleware(['api.token', 'throttle:api-token'])
    ->name('api.v1.')
    ->group(function () {
        Route::get('/status', [StatusController::class, 'show'])->name('status.show');
        Route::patch('/status', [StatusController::class, 'update'])->name('status.update');
    });
