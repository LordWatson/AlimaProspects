<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Actions\ExportPlatformReportAction;
use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Admin\Http\Requests\ExportPlatformReportRequest;
use App\Modules\Admin\Http\Requests\PlatformWindowRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\Platform\PlatformReportBuilder;
use App\Modules\Reporting\Support\ReportFilterOptions;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform reporting (Phase 19c) — **is this business working, and is this customer profitable**.
 *
 * Thin, like every controller here: validate the window, call one builder, render. Server-driven
 * filtering, the same choice the tenant dashboard makes, so a URL an operator pastes into a
 * conversation reproduces exactly the figures they were looking at — and the export link carries
 * the same window.
 *
 * **Not audited as a privacy event**, unlike a resource index. Every figure on the page is an
 * aggregate and names nobody — with one row that comes close, the margin report's tenant column,
 * which carries a company name and no personal data. The *export* is audited, because a file is a
 * copy that leaves the panel.
 */
final class PlatformReportingController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly PlatformReportBuilder $builder,
    ) {}

    public function index(PlatformWindowRequest $request): Response
    {
        $window = $request->window();

        return Inertia::render('admin/reporting/Index', [
            'navigation' => $this->registry->navigation(),
            'report' => $this->builder->build($window),
            'filters' => $request->formState(),
            // The preset list reused rather than restated: it is the same question the tenant
            // dashboard asks, and two lists would eventually offer different windows. `ranges()`
            // rather than `all()`, because `all()` also carries the **tenant** report list and
            // choosing one of those on an operator's screen would mean nothing.
            'ranges' => ReportFilterOptions::ranges(),
            'reports' => array_map(
                static fn (PlatformReport $report): array => [
                    'value' => $report->value,
                    'label' => $report->label(),
                    'caption' => $report->caption(),
                ],
                PlatformReport::tabs(),
            ),
        ]);
    }

    public function export(
        ExportPlatformReportRequest $request,
        ExportPlatformReportAction $export,
    ): StreamedResponse {
        return $export->handle($request->report(), $request->window());
    }
}
