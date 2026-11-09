<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Auth\Policies\TeamPolicy;
use App\Modules\ModuleServiceProvider;
use App\Modules\Reporting\Contracts\UsageSummarySource;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use App\Modules\Reporting\Services\NoUsageSummary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reporting & Analytics module provider. It registers the module's routes (via the base provider)
 * and the export rate limiter: the aggregation Actions, {@see DashboardReportBuilder} and the
 * table projector are plain constructor-injected classes the container can autowire, and
 * authorisation lives on {@see TeamPolicy::viewReports()} because "may you see these numbers" is a
 * question about the team, not about a reporting model (the module owns none).
 */
final class ReportingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // The module's own answer to "what is this team allowed": nothing to show. Billing is
        // registered last and binds a plan-backed source over it (ADR 0012), so the dashboard's
        // allowance panel exists because Billing is installed rather than because Reporting learnt
        // what a plan is.
        $this->app->bind(UsageSummarySource::class, NoUsageSummary::class);
    }

    public function boot(): void
    {
        parent::boot();

        // A CSV export re-runs the whole dashboard (see ExportReportAction — the download has to
        // agree with the screen), so it is cheap to ask for and not cheap to serve. Per member
        // rather than per IP, so a shared office doesn't throttle itself.
        RateLimiter::for('reports-export', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('reporting.export_rate_limit', 20)),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
