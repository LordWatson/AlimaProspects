<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\PlatformOverview;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The panel's front page (Phase 19a).
 *
 * Thin, like every other controller here: it calls {@see PlatformOverview} and renders. The
 * figures are deliberately a handful of counts and nothing more — **platform reporting is Phase
 * 19c**, and it is a different thing built differently, over a nightly `platform_daily_metrics`
 * rollup rather than live aggregates. What is here is the set an operator wants on the way to
 * somewhere else: how many tenants, how many are suspended, is anything on fire in the last day.
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
    public function __invoke(ResourceRegistry $registry, PlatformOverview $overview): Response
    {
        return Inertia::render('admin/Dashboard', [
            'navigation' => $registry->navigation(),
            'stats' => $overview->counts(),
            'suspended' => $overview->suspendedTeams(),
        ]);
    }
}
