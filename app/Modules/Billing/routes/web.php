<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\Web\BillingController;
use Illuminate\Support\Facades\Route;

/*
 * Billing & Plans web routes (Inertia, Phase 16).
 *
 * Web only, and there is no `api.php` beside this file: billing is not in the mobile app (ADR 0001
 * / `billing-plans.md`), and an endpoint that returns a Stripe Checkout URL to an API token would
 * be a way to start a subscription without a person present.
 *
 * Top-level `/billing` rather than `/settings/billing`, and in the sidebar's account group beside
 * Compliance rather than in the settings nav. Two reasons: the settings pane is a 36rem column
 * built for forms, and a price list of four plans beside two usage meters does not go in it; and
 * the plan is a thing every member may look at, where the rest of that pane is one person's own
 * profile. Same shape as Compliance, and for the same reason.
 *
 * `checkout` and `portal` are POSTs although they only redirect, because each one creates something
 * at Stripe — a checkout session, a portal session — and a GET that creates is a GET a link
 * prefetcher can fire.
 *
 * Stripe's own webhook route is registered by Cashier at `stripe/webhook`; this module listens for
 * what it handles rather than owning the route (see `Listeners\SyncSubscriptionFromStripe`).
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('billing', [BillingController::class, 'index'])
        ->name('billing.index');

    Route::post('billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');

    Route::put('billing/plan', [BillingController::class, 'swap'])
        ->name('billing.swap');

    Route::delete('billing/subscription', [BillingController::class, 'cancel'])
        ->name('billing.cancel');

    Route::post('billing/subscription', [BillingController::class, 'resume'])
        ->name('billing.resume');

    Route::post('billing/portal', [BillingController::class, 'portal'])
        ->name('billing.portal');
});
