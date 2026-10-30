<?php

use App\Modules\AiInsights\Providers\AiInsightsServiceProvider;
use App\Modules\AiPlatform\Providers\AiPlatformServiceProvider;
use App\Modules\Auth\Providers\AuthServiceProvider as AuthModuleServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\BusinessIntelligence\Providers\BusinessIntelligenceServiceProvider;
use App\Modules\Compliance\Providers\ComplianceServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Enrichment\Providers\EnrichmentServiceProvider;
use App\Modules\ICP\Providers\IcpServiceProvider;
use App\Modules\LeadSourcing\Providers\LeadSourcingServiceProvider;
use App\Modules\LeadsWorkspace\Providers\LeadsWorkspaceServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Onboarding\Providers\OnboardingServiceProvider;
use App\Modules\Outreach\Providers\OutreachServiceProvider;
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
    OutreachServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportingServiceProvider::class,
    AiInsightsServiceProvider::class,
    // Last on purpose: it replaces Lead Sourcing's LIA stub and Outreach's restriction resolver
    // with the real ones, so it must register after both (ADR 0002 / docs/compliance.md §6).
    ComplianceServiceProvider::class,
    // Also last, and for the same reason: it replaces AI Platform's credit stub, Lead Sourcing's
    // lead-quota stub and Auth's seat stub with plan-backed sources (Phase 16).
    BillingServiceProvider::class,
];
