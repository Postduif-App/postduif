<?php

use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\ContractController as ApiContractController;
use App\Http\Controllers\Api\V1\ContractDocumentController;
use App\Http\Controllers\Api\V1\ContractTemplateController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\InboundMailController;
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
 * An e-mail somebody sent to the workspace, posted here by whichever provider
 * receives it. Beside the two webhooks above because it is the same shape — a
 * machine calling in with a secret in the path — and in this file for the same
 * reason: there is no session, no CSRF token and nobody to redirect.
 *
 * What comes back is always 200 unless the token is unknown. See the
 * controller: a provider treats anything else as "retry", and nothing that can
 * go wrong here gets better on the third attempt.
 */
Route::post('/mail/inbound/{token}', InboundMailController::class)
    ->middleware('throttle:inbound-mail')
    ->name('mail.inbound');

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

        /*
         * Reading the list before writing to it, because posting needs a
         * channel id and there is nowhere else to get one: the screen does not
         * show ids and the tool that finds them needs an AI client to call it.
         */
        Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');

        /*
         * Contracts, behind a scope of their own.
         *
         * The first thing in this API that is not simply "the member, over
         * HTTP". Sending a contract puts a workspace's name under a request to
         * somebody outside it, so a token has to have been made for it on
         * purpose — an older token, minted when this endpoint did not exist,
         * carries no scope and is refused. See RequireApiScope, and
         * ResolvesTokenWorkspace for why these also need a token tied to one
         * workspace.
         *
         * Ids in the paths, unlike everything above. A contract is a thing
         * rather than a fact about the caller, and there is no reading of "the
         * contract" that a token alone could resolve.
         */
        Route::middleware('api.scope:contracts')->group(function () {
            Route::get('/contract-templates', [ContractTemplateController::class, 'index'])
                ->name('contract-templates.index');

            Route::get('/contracts', [ApiContractController::class, 'index'])->name('contracts.index');

            /*
             * Throttled harder than the rest, and separately: this is the one
             * endpoint in the API that reaches a stranger's inbox — the same
             * reason the screens' send route carries its own limit.
             */
            Route::post('/contracts', [ApiContractController::class, 'store'])
                ->middleware('throttle:contract-send')
                ->name('contracts.store');

            Route::get('/contracts/{contract}', [ApiContractController::class, 'show'])->name('contracts.show');

            Route::get('/contracts/{contract}/document', ContractDocumentController::class)
                ->name('contracts.document');
        });
    });
