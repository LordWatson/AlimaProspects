<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Providers;

use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Integrations\Console\PruneWebhookDeliveriesCommand;
use App\Modules\Integrations\Contracts\CrmRowAllowance;
use App\Modules\Integrations\Listeners\AutoSyncScoredLead;
use App\Modules\Integrations\Listeners\RelayWebhookEvents;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\WebhookEndpoint;
use App\Modules\Integrations\Observers\LeadWebhookObserver;
use App\Modules\Integrations\Policies\IntegrationPolicy;
use App\Modules\Integrations\Policies\WebhookEndpointPolicy;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\UnmeteredCrmRows;
use App\Modules\Integrations\Services\WebhookDispatcher;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\ModuleServiceProvider;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Signals\Events\SignalsDetected;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Integrations module provider (PL7).
 *
 * **This module listens outwards and nothing listens to it.** Four hookups below, all on events and
 * a model that belong to other modules, which is the same arrangement the Leads Workspace timeline
 * uses — adding a webhook event is a method on {@see RelayWebhookEvents}, never a line in whoever
 * raised it.
 *
 * `PruneWebhookDeliveriesCommand` runs at 03:55, after PL6's export prune, because both are nightly
 * sweeps and nothing depends on their order.
 *
 * No connector is registered here: PL8 and PL9 bring them, and until then
 * {@see CrmProvider::isAvailable()} answers false and the screen says so.
 */
final class IntegrationsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        WebhookEndpoint::class => WebhookEndpointPolicy::class,
        Integration::class => IntegrationPolicy::class,
    ];

    public function register(): void
    {
        // The registry has to be one instance: a connector registered on a second copy would be
        // invisible to the job that needs it.
        $this->app->singleton(CrmConnectorRegistry::class);

        // **`scoped()`, not `singleton()`.** The memo has to survive a whole discovery run — which
        // is the case it exists for — and must *not* survive the life of a `queue:work` process,
        // because a singleton would mean an endpoint added after the worker booted received no
        // events at all until somebody restarted it. `scoped()` is reset per request and per job,
        // which is exactly the boundary wanted (`PlanResolver` is bound this way for the same
        // reason).
        $this->app->scoped(WebhookDispatcher::class);

        // A push is not metered unless Billing says so (PL7's seam — the seventh of ADR 0012's).
        $this->app->bind(CrmRowAllowance::class, UnmeteredCrmRows::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(LeadScored::class, [RelayWebhookEvents::class, 'onLeadScored']);
        Event::listen(LeadEnriched::class, [RelayWebhookEvents::class, 'onLeadEnriched']);
        Event::listen(SignalsDetected::class, [RelayWebhookEvents::class, 'onSignalsDetected']);

        // The auto-sync rule hangs off the same event as `lead.updated`, deliberately: the moment a
        // lead's worth changed is the only moment worth re-asking whether it should be in the CRM.
        Event::listen(LeadScored::class, AutoSyncScoredLead::class);

        // `lead.created` has no domain event to listen to, and this is the mechanism ADR 0028 chose
        // for exactly this reason — see the observer.
        Lead::observe(LeadWebhookObserver::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('integrations:prune-deliveries')->dailyAt('03:55')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneWebhookDeliveriesCommand::class]);
        }
    }
}
