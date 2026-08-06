<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Who may read the queue outside local development.
     *
     * The same door as the admin panel rather than the list of addresses this
     * scaffolding ships with: who moderates the platform is already a column,
     * and a second list beside it is one that goes out of step the first time
     * somebody is promoted or suspended. A suspended moderator loses this too,
     * for the reason canAccessPanel() gives.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null
            && $user->isAdmin()
            && ! $user->isSuspended());
    }
}
