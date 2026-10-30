<?php

use App\Modules\AiPlatform\Providers\AiPlatformServiceProvider;
use App\Modules\Auth\Providers\AuthServiceProvider as AuthModuleServiceProvider;
use App\Modules\BusinessIntelligence\Providers\BusinessIntelligenceServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Enrichment\Providers\EnrichmentServiceProvider;
use App\Modules\ICP\Providers\IcpServiceProvider;
use App\Modules\LeadSourcing\Providers\LeadSourcingServiceProvider;
use App\Modules\LeadsWorkspace\Providers\LeadsWorkspaceServiceProvider;
use App\Modules\Onboarding\Providers\OnboardingServiceProvider;
use App\Modules\Reporting\Providers\ReportingServiceProvider;
use App\Modules\Scoring\Providers\ScoringServiceProvider;
use App\Modules\Signals\Providers\SignalsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,

    // Domain modules (ADR 0002) — register each module's provider here.
    CoreServiceProvider::class,
    AuthModuleServiceProvider::class,
    AiPlatformServiceProvider::class,
    OnboardingServiceProvider::class,
    IcpServiceProvider::class,
    BusinessIntelligenceServiceProvider::class,
    LeadSourcingServiceProvider::class,
    EnrichmentServiceProvider::class,
    ScoringServiceProvider::class,
    LeadsWorkspaceServiceProvider::class,
    SignalsServiceProvider::class,
    ReportingServiceProvider::class,
];
