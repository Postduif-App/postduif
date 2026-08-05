<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAccountIsNotSuspended;
use App\Http\Middleware\EnsureFeatureIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleLocale;
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

        $middleware->alias([
            'feature' => EnsureFeatureIsActive::class,
            /*
             * The same middleware the MCP server uses. Reused rather than
             * copied: it resolves a personal token to its member and stamps
             * last_used_at, which is exactly what an API caller needs.
             */
            'api.token' => AuthenticateApiToken::class,
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
            EnsureAccountIsNotSuspended::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
