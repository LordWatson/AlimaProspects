<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Providers;

use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\BusinessIntelligence\Policies\BusinessIntelReportPolicy;
use App\Modules\ModuleServiceProvider;

final class BusinessIntelligenceServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        BusinessIntelReport::class => BusinessIntelReportPolicy::class,
    ];

    // The web fetcher the background-check job depends on is bound in Core (the shared
    // App\Modules\Core\Contracts\WebContentFetcher), so this module registers nothing of its own.
}
