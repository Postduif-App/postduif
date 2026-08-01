<?php

namespace App\Providers;

use App\Models\Webhook;
use App\Support\Dns\DnsHostResolver;
use App\Support\Dns\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * What a hostname stands for is the security decision behind link
         * previews — see PublicUrl. Bound rather than newed up, so a test can
         * answer that question without asking the machine's own resolver, which
         * on a development box points *.test at localhost.
         */
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Chat is unusable without a websocket server, so "php artisan dev"
        // starts Reverb alongside the HTTP server, queue worker and Vite.
        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * A webhook is a machine with a single credential, so it gets a budget of
     * its own rather than sharing one with everything coming from the same IP —
     * one noisy integration must not be able to silence the others.
     *
     * Keyed by the hash of the token rather than the token, so a secret never
     * ends up as a cache key.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)
            ->by(Webhook::hashToken((string) $request->route('token'))));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
