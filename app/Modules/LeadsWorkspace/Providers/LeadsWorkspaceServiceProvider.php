<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Providers;

use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\LeadsWorkspace\Listeners\RecordDiscoveryActivity;
use App\Modules\LeadsWorkspace\Listeners\RecordEnrichmentActivity;
use App\Modules\LeadsWorkspace\Listeners\RecordScoringActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\LeadsWorkspace\Policies\LeadNotePolicy;
use App\Modules\LeadsWorkspace\Policies\LeadTaskPolicy;
use App\Modules\LeadsWorkspace\Policies\PipelineStagePolicy;
use App\Modules\LeadsWorkspace\Policies\SavedViewPolicy;
use App\Modules\LeadsWorkspace\Services\NoLeadOrigin;
use App\Modules\ModuleServiceProvider;
use App\Modules\Scoring\Events\LeadScored;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Leads Workspace module provider (Phase 10). Registers the saved-view policy and the three
 * listeners that feed a lead's timeline.
 *
 * Every listener is **queued** and writes only history: the timeline is a read model, so nothing
 * upstream should slow down — or fail — because the workspace wanted to record something. The
 * workspace listens outwards and the other modules know nothing about it — including for the lead
 * detail screen's origin panel, which asks {@see LeadOriginProvider} instead of querying a module
 * it would otherwise have to depend on.
 */
final class LeadsWorkspaceServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        SavedView::class => SavedViewPolicy::class,
        PipelineStage::class => PipelineStagePolicy::class,
        LeadNote::class => LeadNotePolicy::class,
        LeadTask::class => LeadTaskPolicy::class,
    ];

    public function register(): void
    {
        // No lead has an origin beyond its source unless a module that creates leads says otherwise
        // (Signals rebinds this in S4). Bound here so the detail screen stands on its own.
        $this->app->bind(LeadOriginProvider::class, NoLeadOrigin::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DiscoveryRunCompleted::class, RecordDiscoveryActivity::class);
        Event::listen(LeadEnriched::class, RecordEnrichmentActivity::class);
        Event::listen(LeadScored::class, RecordScoringActivity::class);

        // Bulk work — a bulk action, a CSV import, a CSV export — is cheap to ask for and
        // expensive to serve: one click can touch `max_bulk_leads` leads or stream a team's whole
        // list. This caps how fast one member can ask, per member rather than per IP so a shared
        // office doesn't throttle itself.
        RateLimiter::for('leads-bulk', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('leads-workspace.bulk_rate_limit', 20)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
