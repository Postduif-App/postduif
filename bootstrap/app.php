<?php

use App\Http\Middleware\EnsureAccountIsNotSuspended;
use App\Http\Middleware\EnsureFeatureIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
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
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'member_panel_state']);

        $middleware->alias(['feature' => EnsureFeatureIsActive::class]);

        // After HandleInertiaRequests on purpose: a suspended member gets sent
        // back to the login screen, and Inertia has to turn that redirect into
        // something its client understands.
        $middleware->web(append: [
            HandleAppearance::class,
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
