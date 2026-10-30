<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Auth\Policies\TeamPolicy;
use App\Modules\ModuleServiceProvider;
use App\Modules\Reporting\Services\DashboardReportBuilder;

/**
 * Reporting & Analytics module provider. It registers only the module's routes (via the base
 * provider): the aggregation Actions and {@see DashboardReportBuilder}
 * are plain constructor-injected classes the container can autowire, and authorisation lives on
 * {@see TeamPolicy::viewReports()} because "may you see these numbers"
 * is a question about the team, not about a reporting model (the module owns none).
 */
final class ReportingServiceProvider extends ModuleServiceProvider {}
