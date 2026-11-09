<?php

declare(strict_types=1);

use App\Modules\Integrations\Http\Controllers\Web\CrmIntegrationController;
use App\Modules\Integrations\Http\Controllers\Web\IntegrationController;
use App\Modules\Integrations\Http\Controllers\Web\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
 * Integrations web routes (Inertia, PL7).
 *
 * Web only, and there is no `api.php` beside this file. That is a decision rather than an omission:
 * `/api/v1` is how a customer's own script reads Alima, and webhooks are how Alima reaches their
 * script — adding an endpoint through a token would let a credential configure where the team's
 * whole lead flow is sent, which is a thing a person should do while looking at the screen that
 * says so. The same argument Billing makes for having no API routes.
 *
 * Top-level `/integrations`, not `/settings/integrations`: the settings pane is a 36rem column
 * built for forms and the delivery log does not fit in it (see the controller).
 *
 * Every route is behind `PrelaunchFeature::Webhooks`, checked in the controllers rather than by
 * middleware, because the flag is resolved per **team** and a team is only known once the request
 * has a user.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('integrations', [IntegrationController::class, 'index'])
        ->name('integrations.index');

    // --- Outbound webhooks -------------------------------------------------------------------
    Route::post('integrations/webhooks', [WebhookEndpointController::class, 'store'])
        ->name('integrations.webhooks.store');

    Route::put('integrations/webhooks/{endpoint}', [WebhookEndpointController::class, 'update'])
        ->whereNumber('endpoint')
        ->name('integrations.webhooks.update');

    Route::delete('integrations/webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy'])
        ->whereNumber('endpoint')
        ->name('integrations.webhooks.destroy');

    // A POST because it replaces a credential, which is the most consequential button here.
    Route::post('integrations/webhooks/{endpoint}/rotate', [WebhookEndpointController::class, 'rotate'])
        ->whereNumber('endpoint')
        ->name('integrations.webhooks.rotate');

    Route::post('integrations/webhooks/{endpoint}/test', [WebhookEndpointController::class, 'test'])
        ->whereNumber('endpoint')
        ->name('integrations.webhooks.test');

    Route::post('integrations/deliveries/{delivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])
        ->whereNumber('delivery')
        ->name('integrations.deliveries.redeliver');

    // --- CRM connections ---------------------------------------------------------------------
    /*
     * `connect` is a POST although it only redirects: it mints a single-use `state`, and a GET that
     * creates is a GET a link prefetcher fires — which would burn the state the member is about to
     * use. `callback` is a GET because the provider decides that, and the `state` is what stands in
     * for the CSRF token it cannot have.
     */
    Route::post('integrations/crm/{provider}/connect', [CrmIntegrationController::class, 'connect'])
        ->name('integrations.crm.connect');

    Route::get('integrations/crm/{provider}/callback', [CrmIntegrationController::class, 'callback'])
        ->name('integrations.crm.callback');

    Route::put('integrations/crm/{integration}', [CrmIntegrationController::class, 'update'])
        ->whereNumber('integration')
        ->name('integrations.crm.update');

    Route::delete('integrations/crm/{integration}', [CrmIntegrationController::class, 'destroy'])
        ->whereNumber('integration')
        ->name('integrations.crm.destroy');

    Route::post('integrations/crm/{integration}/leads/{lead}', [CrmIntegrationController::class, 'sync'])
        ->whereNumber(['integration', 'lead'])
        ->name('integrations.crm.sync');
});
