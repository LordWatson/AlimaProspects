<?php

namespace App\Providers;

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
     * Register the Horizon gate.
     *
     * Deliberately empty: `viewHorizon` is defined in {@see ObservabilityServiceProvider}, beside
     * `viewPulse`, because the two gates answer the same question and both shipped with the same
     * hard-coded-email-list default (Phase 20, ADR 0017). Overriding it here to nothing — rather
     * than deleting the method, which the parent calls — keeps one definition of who may read
     * every tenant's queue.
     */
    protected function gate(): void
    {
        //
    }
}
