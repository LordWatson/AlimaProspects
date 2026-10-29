<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Providers;

use App\Modules\BusinessIntelligence\Contracts\WebContentFetcher;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\BusinessIntelligence\Policies\BusinessIntelReportPolicy;
use App\Modules\BusinessIntelligence\Services\HttpWebContentFetcher;
use App\Modules\ModuleServiceProvider;

final class BusinessIntelligenceServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        BusinessIntelReport::class => BusinessIntelReportPolicy::class,
    ];

    public function register(): void
    {
        // The minimal web fetcher behind the contract — swapped for a fake in tests, and later for
        // the Lead Sourcing web-search provider (Phase 7b) without touching the job.
        $this->app->bind(WebContentFetcher::class, HttpWebContentFetcher::class);
    }
}
