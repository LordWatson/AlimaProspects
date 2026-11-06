<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\Platform\PlatformPulse;
use App\Modules\Admin\Services\PlatformOverview;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The panel's front page (Phase 19a).
 *
 * Thin, like every other controller here: it calls two services and renders.
 *
 * {@see PlatformOverview} answers *how big is this thing* with live counts. {@see PlatformPulse}
 * answers *is it growing, and is anything on fire* from the nightly `platform_daily_metrics`
 * rollup — the same source as platform reporting, because a figure derived two ways is a figure
 * that will eventually disagree with itself.
 *
 * It is still **not** the reporting screen (ADR 0016): no window picker, no cohort rules, five
 * tiles and one chart. Reporting is where a morning's work starts; this is the glance before it.
 *
 * Everything is cross-tenant because `PlatformContext` has cleared the team scope. The counts are
 * aggregates and name nobody, so unlike a resource index this page is **not** audited as a privacy
 * event: there is no personal data in a number.
 *
 * `cost_micros` is summed and labelled in micros, not dressed up as money — micro-units are not
 * minor units.
 */
final class DashboardController extends Controller
{
    public function __invoke(
        ResourceRegistry $registry,
        PlatformOverview $overview,
        PlatformPulse $pulse,
    ): Response {
        return Inertia::render('admin/Dashboard', [
            'navigation' => $registry->navigation(),
            'stats' => $overview->counts(),
            'suspended' => $overview->suspendedTeams(),
            'tiles' => $pulse->tiles(),
            'series' => $pulse->series(),
            'health' => $pulse->health(),
        ]);
    }
}
