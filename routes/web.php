<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ContractDocumentController;
use App\Http\Controllers\ContractSignController;
use App\Http\Controllers\CustomEmojiController;
use App\Http\Controllers\IndexingController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InviteLinkJoinController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\PublicTransferController;
use App\Http\Controllers\SecretAnswerController;
use App\Http\Controllers\SecretFillController;
use App\Http\Controllers\SentSecretRevealController;
use App\Http\Controllers\SessionStatusController;
use Illuminate\Support\Facades\Route;

/**
 * The public site. Outside every auth group on purpose: this is the one part of
 * the application meant for people who have no account and may never get one.
 *
 * Note what it must survive — HandleInertiaRequests shares auth.workspace as
 * null for a signed-out visitor, and the workspace theme as an empty string. A
 * marketing layout that reached for either would work for the developer who is
 * always logged in and break for everybody else.
 */
Route::get('/', [MarketingController::class, 'home'])->name('home');

/**
 * Setting up a platform that has never been set up.
 *
 * Outside auth, like the invitation and transfer links below, and for a
 * stronger version of the same reason: there is not only no account yet, there
 * is nobody who could ever have made one. What stands in for a token here is
 * the state of the platform itself — see EnsureInstallationIsPending, which
 * takes both of these away the moment a workspace or a moderator exists.
 *
 * Throttled like the other doors that create accounts. It answers 404 once the
 * platform is set up, so the ceiling only ever applies to the handful of
 * minutes in which the address is real at all.
 */
Route::middleware('install.pending')->group(function () {
    Route::get('installeren', [InstallController::class, 'show'])->name('install.show');
    Route::post('installeren', [InstallController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('install.store');
});

/*
 * What the API answers to, for somebody about to point a script at it. Public
 * for the same reason the rest of this file is: the endpoints are guarded by a
 * token, and an API whose shape is a secret is one nobody can build against.
 */
Route::get('docs', [MarketingController::class, 'docs'])->name('docs');

/*
 * Routes rather than files in public/: this application runs under whatever
 * hostname it is installed on, and robots.txt has to name its sitemap by
 * absolute URL. See IndexingController, and note that a public/robots.txt
 * would win over this because the web server answers before PHP does.
 */
/*
 * Asking for the other language. Outside auth: the people who need it are the
 * ones with no account to save a preference against. See LocaleController for
 * why it is a link rather than a form.
 */
Route::get('taal/{locale}', LocaleController::class)->name('locale.switch');

Route::get('robots.txt', [IndexingController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [IndexingController::class, 'sitemap'])->name('sitemap');

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
 * Files somebody put aside for you. Outside auth for the same reason as the two
 * above: the person following this link may have no account and may never want
 * one, and the token is what stands in for having been let in.
 *
 * Throttled harder than the pages above, and not only against guessing: these
 * responses are megabytes rather than kilobytes, so an unthrottled download
 * route is a way to spend somebody else's bandwidth by refreshing.
 */
Route::prefix('transfers/{token}')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', [PublicTransferController::class, 'show'])->name('transfers.show');

        /*
         * Answering the password. Throttled far harder than the rest of this
         * group: a password on a public URL is one anybody may sit and guess
         * at, and six tries a minute is the difference between a secret and a
         * formality.
         */
        Route::post('openen', [PublicTransferController::class, 'unlock'])
            ->middleware('throttle:6,1')
            ->name('transfers.unlock');

        // The zip before the single file: "zip" would otherwise be read as a
        // media id by the route below and 404 on its way to being a number.
        Route::get('zip', [PublicTransferController::class, 'downloadAll'])
            ->name('transfers.download-all');
        Route::get('files/{media}', [PublicTransferController::class, 'download'])
            ->name('transfers.download');
    });

/**
 * Filling in a form somebody shared as a link.
 *
 * Outside auth, like a transfer and a sent secret: whoever answers may have no
 * account at all, which is the whole point of sharing a form this way. The
 * token in the address is the permission, so it is looked up by token and never
 * by id, and a withdrawn link answers 404 rather than explaining itself.
 *
 * Throttled on both halves. The GET because a token is a secret and a stream of
 * guesses must cost something; the POST harder still, because it writes into
 * the workspace and nobody legitimately sends a form in ten times a minute.
 */
Route::get('formulier/{token}', [PublicFormController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('forms.public.show');

Route::post('formulier/{token}', [PublicFormController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('forms.public.submit');

/**
 * Signing a contract somebody sent you.
 *
 * Outside auth, like the three above: the person signing may have no account
 * and often is a customer who never will. The token in the address is the whole
 * permission, and it sits in the path rather than in a query string on purpose
 * — a query parameter travels along in the Referer header the moment the page
 * loads anything from elsewhere, and this one is a signature.
 *
 * Throttled on all three. The GET because a token is a secret and a stream of
 * guesses must cost something; the document harder, because it hands over a
 * whole PDF and an unthrottled one is a way to spend somebody else's bandwidth
 * by refreshing; the draft save loosest of the three, because it is called as
 * somebody types and must not run out halfway through a long contract.
 */
Route::prefix('ondertekenen/{token}')
    ->group(function () {
        Route::get('/', [ContractSignController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('contracts.sign.show');

        Route::get('document', [ContractSignController::class, 'document'])
            ->middleware('throttle:20,1')
            ->name('contracts.sign.document');

        Route::post('/', [ContractSignController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('contracts.sign.store');

        /*
         * The mark itself. Throttled tighter than the draft: this one writes a
         * file to disk, and unlike the draft it is pressed once or twice by
         * anybody doing it honestly.
         */
        Route::post('handtekening', [ContractSignController::class, 'signature'])
            ->middleware('throttle:20,1')
            ->name('contracts.sign.signature');

        Route::delete('handtekening', [ContractSignController::class, 'clearSignature'])
            ->middleware('throttle:20,1')
            ->name('contracts.sign.signature.clear');

        Route::get('handtekening/{kind}', [ContractSignController::class, 'signatureImage'])
            ->middleware('throttle:60,1')
            ->name('contracts.sign.signature.show');

        /*
         * The mark of somebody who signed before this person, so their page can
         * show the contract as it now reads. Reached with the reader's own
         * token and the other signer's id — never with the other signer's
         * token, which is permission to sign as them.
         */
        Route::get('getekend/{signer}/{kind}', [ContractSignController::class, 'signerMark'])
            ->middleware('throttle:60,1')
            ->name('contracts.sign.mark.show');

        /*
         * The two endings. Throttled hardest of everything here, and not
         * because they are expensive: they are the requests that cannot be
         * taken back, and a stream of them is either a mistake or somebody
         * hammering at a token. Nobody signs a contract six times a minute.
         */
        Route::post('afronden', [ContractSignController::class, 'complete'])
            ->middleware('throttle:6,1')
            ->name('contracts.sign.complete');

        Route::post('afwijzen', [ContractSignController::class, 'decline'])
            ->middleware('throttle:6,1')
            ->name('contracts.sign.decline');

        /*
         * The signer's own copy of what they signed. Bound to their token, the
         * way everything else on this page is — see the controller for why
         * withholding it would be the one thing this feature must not do.
         */
        Route::get('ondertekend', [ContractSignController::class, 'signedCopy'])
            ->middleware('throttle:20,1')
            ->name('contracts.sign.copy');
    });

/**
 * The signed copy, fetched by a system we sent a webhook to.
 *
 * Outside auth like the signing pages above, and with the same kind of
 * credential in the address — except that here it is a signature Laravel made
 * rather than a token we stored, so nothing has to be looked up and nothing can
 * be guessed. The `signed` middleware refuses a URL that was edited or has run
 * out; DeliverContractWebhookJob is the only place that mints one, and gives it
 * a week.
 *
 * Throttled like the other endpoint that hands over a whole PDF: a valid link is
 * still a link somebody could sit and refresh.
 */
Route::get('contracten/{contract}/document', ContractDocumentController::class)
    ->middleware(['signed', 'throttle:20,1'])
    ->name(ContractDocumentController::ROUTE);

/**
 * Picking up a secret somebody sent you.
 *
 * Outside auth, unlike the form for answering a request below, and for the same
 * reason a transfer link is: the key sits in the fragment of this URL, so
 * holding the link is what grants access and there is no account to check it
 * against. The recipient is often a customer who has none.
 *
 * Throttled hard on the way in. The reveal is the one endpoint in the
 * application that destroys what it returns, so a stream of guesses at ids must
 * be expensive — and where the sender added a password, this throttle is what
 * makes it worth having.
 */
Route::get('geheim/{sentSecret}', [SentSecretRevealController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('sent-secrets.show');

Route::post('geheim/{sentSecret}', [SentSecretRevealController::class, 'reveal'])
    ->middleware('throttle:6,1')
    ->name('sent-secrets.reveal');

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

/*
 * And the face a workspace gave to one of its workflows, which appears beside
 * the messages that workflow posts. Behind the same door for the same reason:
 * a picture uploaded into a private workspace is not the internet's.
 */
Route::get('avatars/workflows/{workflow}', [AvatarController::class, 'workflow'])
    ->middleware(['auth', 'verified'])
    ->name('avatars.workflow');

/*
 * A workspace's own emoji. Beside the avatars and behind the same door: a
 * picture uploaded into a private workspace, handed to the people in it.
 */
Route::get('emoji/{customEmoji}', CustomEmojiController::class)
    ->middleware(['auth', 'verified'])
    ->name('custom-emoji.show');

// Order matters: settings claims /app/settings before chat.php registers the
// /app/{workspace} wildcard that would otherwise match it.
require __DIR__.'/settings.php';
require __DIR__.'/chat.php';

if (! app()->isProduction()) {
    require __DIR__.'/dev.php';
}
