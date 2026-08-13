<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAccountIsNotSuspended;
use App\Http\Middleware\EnsureFeatureIsActive;
use App\Http\Middleware\EnsureInstallationIsPending;
use App\Http\Middleware\EnsureMarketingSiteIsShown;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleLocale;
use App\Http\Middleware\RedirectToInstallation;
use App\Http\Middleware\RequireApiScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'member_panel_state', 'locale']);

        /*
         * Leave a document document exactly as the editor sent it.
         *
         * Both of these walk the request recursively, and a document body is a
         * deep tree of Slate nodes rather than a flat form. An empty paragraph
         * is a text node whose text is "", and ConvertEmptyStringsToNull turns
         * that into null — which Slate will not read back, so one blank line
         * was enough to leave the document unopenable. TrimStrings is the same
         * hazard one step quieter: it would silently eat the indentation and
         * the trailing spaces somebody typed on purpose.
         *
         * Matched on the path rather than the route name, because these run in
         * the global stack before the router has resolved anything.
         *
         * Only the save. Creating a document posts a title and nothing else, and
         * a title that arrives as whitespace should very much still be trimmed.
         */
        $keepsDocumentContentIntact = fn (Request $request): bool => $request->isMethod('PATCH')
            && $request->is('app/*/c/*/documents/*');

        $middleware->convertEmptyStringsToNull(except: [$keepsDocumentContentIntact]);
        $middleware->trimStrings(except: [$keepsDocumentContentIntact]);

        $middleware->alias([
            'feature' => EnsureFeatureIsActive::class,
            // The onboarding screen, which is only a screen until the platform
            // has been set up. On the routes rather than in the controller: the
            // POST needs the same door as the GET.
            'install.pending' => EnsureInstallationIsPending::class,
            /*
             * De publieke site, die alleen op de gehoste uitgave bestaat. Ook
             * hier op de route: het is geen keuze over wat een pagina toont
             * maar over of dit adres hier iets voorstelt.
             */
            'marketing.site' => EnsureMarketingSiteIsShown::class,
            /*
             * The same middleware the MCP server uses. Reused rather than
             * copied: it resolves a personal token to its member and stamps
             * last_used_at, which is exactly what an API caller needs.
             */
            'api.token' => AuthenticateApiToken::class,
            /*
             * Beside it rather than folded into it: `api.token` says who is
             * calling, this says whether their token was cut for the door they
             * are at. Routes that have always been open to any personal token
             * carry only the first, which is what keeps them working for tokens
             * minted before scopes existed.
             */
            'api.scope' => RequireApiScope::class,
        ]);

        // After HandleInertiaRequests on purpose: a suspended member gets sent
        // back to the login screen, and Inertia has to turn that redirect into
        // something its client understands.
        /*
         * The API answers in the caller's language too. It sits outside the web
         * group on purpose — no session, no CSRF — but a refusal saying "Je bent
         * geen lid van deze workspace" to a script whose Accept-Language says
         * English is the same half-translation as anywhere else.
         *
         * HandleLocale needs nothing from a session: it reads the member behind
         * the token first, and the header after.
         */
        $middleware->api(append: [HandleLocale::class]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleLocale::class,
            HandleInertiaRequests::class,
            /*
             * A platform nobody has set up yet has one screen, and this puts
             * everybody on it. After HandleInertiaRequests for the same reason
             * the suspension check is: this answers with a redirect, and an
             * Inertia visit needs that turned into something its client
             * understands.
             */
            RedirectToInstallation::class,
            EnsureAccountIsNotSuspended::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
