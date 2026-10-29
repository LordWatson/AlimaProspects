# Graph Report - AlimaProspects (2026-10-29)

## Corpus Check

- 711 files · ~172,111 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 22 file(s) not represented in the graph (top: (none) 17, .example 1, .neon 1)

## Summary

- 4152 nodes · 9667 edges · 211 communities (129 shown, 82 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 451 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness

- Built from commit: `398f6473`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)

- UI Component Library
- Auth & Teams (domain)
- Dashboard & App Shell
- ICP Profiles
- Illuminate\Http\Request
- Business Intelligence
- Module Docs & ADRs
- TeamInvitation
- UI Components (forms)
- App Header
- StartDiscoveryRunAction.php
- Auth Pages (frontend)
- Onboarding & ICP Builder
- TeamContext
- ICP Tests
- AI Platform (AiClient)
- lead-sourcing/Index.vue
- Project Rules (CLAUDE.md)
- UI Components (nav/label)
- CI Workflow
- AppSidebar.vue
- Two-Factor Setup UI
- UI Components (Select)
- Core: i18n & Localisation
- EnrichLeadJob
- Lead Source Providers
- OnboardingStep
- Frontend Global Types
- Settings Pages
- UI Components (Dialog)
- Module: AiPlatform
- Tests: LeadSourcing
- useAppearance.ts
- DomainException
- Illuminate\Database\Eloquent\Model
- Tests: AiPlatform
- Module: AiPlatform
- Tests: Settings
- Market
- Tests: Enrichment
- Tests: BusinessIntelligence
- AiTransport
- App\Modules\ICP\Models\Icp
- Illuminate\Support\Str
- Controller
- Module: ICP
- App\Modules\Core\Models\Team
- Frontend: components/ui
- Module: LeadSourcing
- EmailVerificationStatus
- ScoreGrade
- CompaniesHouseProviderTest.php
- Frontend: components/ui
- Tests: Onboarding
- Module: ModuleServiceProvider.php
- Module: AiPlatform
- CircuitBreaker
- Module: AiPlatform
- package.json
- Frontend: components/ui
- root
- Tests: Enrichment
- BusinessProfile
- Tests: Core
- CreateTeamData
- Module: BusinessIntelligence
- ScoreLeadActionTest.php
- ScoringWebTest.php
- Module: BusinessIntelligence
- ScoringTenancyTest.php
- Illuminate\Database\Eloquent\Factories\Factory
- Frontend: components/ui
- DiscoverLeadsJob
- root
- Illuminate\Foundation\Http\FormRequest
- DeterministicScorer
- @inertiajs/vue3
- root
- ScoreLeadJob
- Tests: BusinessIntelligence
- DiscoveryQuery
- App\Models\User
- app
- Database: migrations
- Frontend: components/ui
- Review.vue
- Tests: Auth
- Module: Core
- Database: factories
- root
- AlertError.vue
- .callCount
- Tests: LeadSourcing
- Tests: LeadSourcing
- CriterionScore
- AiPromptTemplateFactory
- Module: Auth
- DeterministicScorerTest.php
- Tests: Enrichment
- root
- AiTier
- app
- Module: BusinessIntelligence
- Throwable
- Module: Enrichment
- Inertia\Testing\AssertableInertia
- ScoringServiceProvider.php
- app
- Module: Onboarding
- root
- Infra: .github
- .first
- Database: factories
- Database: factories
- CriterionMatch
- .feedback
- Database: seeders
- Tests: Auth
- App\Modules\Core\Support\TeamContext
- Tests: Auth
- Web/ScoreController.php
- root
- Spatie\LaravelData\Data
- resources
- Tests: AiPlatform
- ServedRegion
- AiRequestFactory.php
- Database: migrations
- Database: migrations
- Tests: Auth
- providers.php
- FeedbackRating
- BusinessProfileFactory.php
- ApiTokenTest.php
- ScoringApiTest.php
- app
- App\Modules\ICP\Enums\CriterionType
- Database: migrations
- Tests: Auth
- V1/ScoreController.php
- root
- WizardProgress.vue
- ScoreLeadJobTest.php
- app
- HandleInertiaRequests.php
- Tests: Settings
- SaveOnboardingStepRequest.php
- root
- root
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- DnsResolver
- app
- EmailVerifier
- badge/index.ts
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Docs: ai-prompts.md
- IssueApiTokenRequest
- root
- Docs: compliance.md
- Docs: brand
- Docs: brand
- Docs: brand
- Docs: modules
- AcceptBackgroundCheckRequest
- 2026_10_29_130002_create_leads_table.php
- 2026_10_29_150001_create_lead_scores_table.php
- 2026_10_29_150002_create_score_feedback_table.php
- BackgroundCheckStatus.php

## God Nodes (most connected - your core abstractions)

1. `Team` - 174 edges
2. `vue` - 148 edges
3. `User` - 147 edges
4. `TeamContext` - 111 edges
5. `createUserWithTeam()` - 110 edges
6. `Lead` - 107 edges
7. `cn()` - 103 edges
8. `reka-ui` - 78 edges
9. `AuditLogger` - 62 edges
10. `LeadScore` - 57 edges

## Surprising Connections (you probably didn't know these)

- `{closure#4}()` --calls--> `DiscoveryQuery` [INFERRED]
  tests/Feature/LeadSourcing/DiscoveryGuardsTest.php → app/Modules/LeadSourcing/Data/DiscoveryQuery.php
- `{closure#6}()` --calls--> `IcpUpdated` [EXTRACTED]
  tests/Feature/Scoring/RescoreTriggerTest.php → app/Modules/ICP/Events/IcpUpdated.php
- `{closure#2}()` --references--> `Lead` [EXTRACTED]
  tests/Feature/Enrichment/EnrichLeadActionTest.php → app/Modules/LeadSourcing/Models/Lead.php
- `{closure#3}()` --references--> `Lead` [EXTRACTED]
  tests/Feature/Enrichment/EnrichLeadActionTest.php → app/Modules/LeadSourcing/Models/Lead.php
- `{closure#2}()` --references--> `Lead` [EXTRACTED]
  tests/Feature/Enrichment/EnrichLeadJobTest.php → app/Modules/LeadSourcing/Models/Lead.php

## Import Cycles

- 3-file cycle: `resources/js/components/ui/sidebar/SidebarMenuButton.vue -> resources/js/components/ui/sidebar/SidebarMenuButtonChild.vue -> resources/js/components/ui/sidebar/index.ts -> resources/js/components/ui/sidebar/SidebarMenuButton.vue`

## Hyperedges (group relationships)

- **Reverb live progress broadcasting** — docs_modules_notifications_reverb, docs_modules_lead_sourcing_lead_sourcing, docs_modules_business_intelligence_business_intelligence, docs_modules_notifications_notifications [EXTRACTED 0.80]
- **AiClient consumers (structured AI across modules)** — docs_modules_ai_platform_aiclient, docs_modules_business_intelligence_business_intelligence, docs_modules_enrichment_data_quality_enrichment_data_quality, docs_modules_scoring_matching_scoring_matching, docs_modules_outreach_outreach, docs_modules_ai_insights_ai_insights [EXTRACTED 0.85]
- **Phase definition-of-done: gates + reviewers + status table** — _claude_skills_phase_complete_skill_checklist, _claude_skills_quality_gates_skill_gates, _claude_agents_laravel_module_reviewer_reviewer, _claude_agents_brand_reviewer_guardian, mvp_phase_status_table [EXTRACTED 0.85]
- **Lead lifecycle: source → enrich → score → workspace → outreach** — docs_modules_lead_sourcing_lead_sourcing, docs_modules_enrichment_data_quality_enrichment_data_quality, docs_modules_scoring_matching_scoring_matching, docs_modules_leads_workspace_leads_workspace, docs_modules_outreach_outreach [EXTRACTED 0.90]
- **Signal Orange decorative-only rule enforced across docs** — claude_brand_law, docs_brand_brand_tokens_signal_orange_rule, _claude_agents_brand_reviewer_guardian [EXTRACTED 0.90]
- **Scraped/web content treated as data, never instructions** — docs_architecture_ai_safety, docs_ai_prompts_injection_safety, docs_compliance_scraping_ethics [EXTRACTED 0.90]

## Communities (211 total, 82 thin omitted)

### Community 0 - "UI Component Library"

Cohesion: 0.04
Nodes (60): vue, props, props, props, props, props, props, props (+52 more)

### Community 1 - "Auth & Teams (domain)"

Cohesion: 0.05
Nodes (25): {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}(), RemoveTeamMemberAction, {closure#1}(), {closure#2}(), UpdateTeamMemberRoleAction (+17 more)

### Community 2 - "Dashboard & App Shell"

Cohesion: 0.04
Nodes (43): ActiveIcon, { appearance, updateAppearance }, options, { t }, props, { locale, available, setLocale, t }, { isMobile, state }, { isRtl } (+35 more)

### Community 3 - "ICP Profiles"

Cohesion: 0.06
Nodes (24): {closure#1}(), {closure#2}(), {closure#1}(), FindIcpAction, {closure#1}(), GetIcpsAction, {closure#1}(), {closure#2}() (+16 more)

### Community 4 - "Illuminate\Http\Request"

Cohesion: 0.06
Nodes (17): {closure#1}(), RevokeApiTokenAction, ApiTokenController, TeamController, TeamSwitchController, ApiTokenResource, TeamResource, UserResource (+9 more)

### Community 5 - "Business Intelligence"

Cohesion: 0.07
Nodes (16): {closure#1}(), User, {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}(), FindReportAction, {closure#1}() (+8 more)

### Community 6 - "Module Docs & ADRs"

Cohesion: 0.06
Nodes (72): ADR 0002 — module structure, ADR 0004 — Fortify + Sanctum + spatie, ADR 0005 — AI via laravel/ai behind AiClient, ADR 0006 — multi-team tenancy, ADR 0007 — UK/UAE markets, bilingual/RTL, compliance, ADR 0008 — lead providers (no paid enrichment MVP), ADR 0010 — web-search provider (Serper), Admin (internal) (+64 more)

### Community 7 - "TeamInvitation"

Cohesion: 0.10
Nodes (11): {closure#1}(), InviteTeamMemberAction, {closure#1}(), ResendInvitationAction, RevokeInvitationAction, InvitationException, TeamInvitationController, {closure#1}() (+3 more)

### Community 8 - "UI Components (forms)"

Cohesion: 0.04
Nodes (45): reka-ui, props, delegatedProps, props, props, emits, forwarded, props (+37 more)

### Community 9 - "App Header"

Cohesion: 0.04
Nodes (27): className, Props, auth, { isCurrentUrl, whenCurrentUrl }, mainNavItems, page, Props, rightNavItems (+19 more)

### Community 10 - "StartDiscoveryRunAction.php"

Cohesion: 0.09
Nodes (10): {closure#1}(), {closure#2}(), StartDiscoveryRunAction, LeadInterestGate, StartDiscoveryData, LeadSourcingException, AllowAllLeadInterestGate, {closure#2}() (+2 more)

### Community 11 - "Auth Pages (frontend)"

Cohesion: 0.07
Nodes (18): @inertiajs/vue3, @laravel/passkeys, emit, name, { register, isLoading, error, isSupported }, showForm, Props, { verify, isLoading, error, isSupported } (+10 more)

### Community 12 - "Onboarding & ICP Builder"

Cohesion: 0.05
Nodes (37): add(), draft, emit, props, remove(), { t }, exclusionRows, form (+29 more)

### Community 13 - "TeamContext"

Cohesion: 0.08
Nodes (16): AcceptInvitationAction, {closure#2}(), {closure#1}(), AcceptBackgroundCheckAction, {closure#1}(), AuditLogger, TeamContext, CreateIcpAction (+8 more)

### Community 14 - "ICP Tests"

Cohesion: 0.08
Nodes (34): {closure#3}(), IcpUpdated, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}(), {closure#15}() (+26 more)

### Community 15 - "AI Platform (AiClient)"

Cohesion: 0.21
Nodes (5): AiRequestStatus, Failed, Success, AiPromptTemplate, ClaudeAiClient

### Community 16 - "lead-sourcing/Index.vue"

Cohesion: 0.03
Nodes (50): props, { t }, variant, confirmingDelete, props, { t }, activeIcp, form (+42 more)

### Community 17 - "Project Rules (CLAUDE.md)"

Cohesion: 0.07
Nodes (28): brand-reviewer agent, laravel-module-reviewer agent, new-module skill, phase-complete skill, quality-gates skill, Changelog, Phase 5 AI Platform (changelog), Phase 6 Business Intelligence (changelog) (+20 more)

### Community 18 - "UI Components (nav/label)"

Cohesion: 0.05
Nodes (33): @vueuse/core, emits, modelValue, props, delegatedProps, props, navigationMenuTriggerStyle, delegatedProps (+25 more)

### Community 19 - "CI Workflow"

Cohesion: 0.05
Nodes (41): env, runs-on, services, steps, DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PASSWORD (+33 more)

### Community 20 - "AppSidebar.vue"

Cohesion: 0.12
Nodes (21): account, initials, isActive(), { isCurrentUrl, isCurrentOrParentUrl }, Item, page, { t }, user (+13 more)

### Community 21 - "Two-Factor Setup UI"

Cohesion: 0.06
Nodes (21): { hasSetupData, clearTwoFactorAuthData }, showSetupModal, code, { copy, copied }, isOpen, modalConfig, pinInputContainerRef, Props (+13 more)

### Community 22 - "UI Components (Select)"

Cohesion: 0.04
Nodes (35): @lucide/vue@^1.17.0, @lucide/vue, delegatedProps, emits, forwarded, props, delegatedProps, emits (+27 more)

### Community 23 - "Core: i18n & Localisation"

Cohesion: 0.12
Nodes (9): {closure#1}(), SupportedLocale, Arabic, English, TextDirection, Ltr, Rtl, {closure#1}() (+1 more)

### Community 24 - "EnrichLeadJob"

Cohesion: 0.11
Nodes (15): EnrichmentStatus, Enriched, Enriching, Failed, Pending, EnrichLeadJob, {closure#1}(), {closure#2}() (+7 more)

### Community 25 - "Lead Source Providers"

Cohesion: 0.08
Nodes (16): LeadSourceProvider, {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), LeadSource, CompaniesHouse, GooglePlaces (+8 more)

### Community 26 - "OnboardingStep"

Cohesion: 0.11
Nodes (12): OnboardingStep, Company, Industry, Locations, Offering, Pricing, Review, {closure#1}() (+4 more)

### Community 27 - "Frontend Global Types"

Cohesion: 0.12
Nodes (18): Auth, CurrentTeam, TeamRole, TeamSummary, TwoFactorConfigContent, User, ComponentCustomProperties, GlobalDirectives (+10 more)

### Community 28 - "Settings Pages"

Cohesion: 0.06
Nodes (18): passwordInput, emit, handleDelete(), isDeleting, props, isRecoveryCodesVisible, recoveryCodeSectionRef, { recoveryCodesList, fetchRecoveryCodes, errors } (+10 more)

### Community 29 - "UI Components (Dialog)"

Cohesion: 0.06
Nodes (23): emits, forwarded, props, props, delegatedProps, emits, forwarded, props (+15 more)

### Community 30 - "Module: AiPlatform"

Cohesion: 0.10
Nodes (6): CreditSource, CreditAllowance, {closure#1}(), AiCreditGuard, AiUsageLogger, StubCreditSource

### Community 31 - "Tests: LeadSourcing"

Cohesion: 0.06
Nodes (28): DiscoveryRun, DiscoveryRunPolicy, FakeLeadSourceProvider, bootDiscovery(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+20 more)

### Community 32 - "useAppearance.ts"

Cohesion: 0.06
Nodes (36): { appearance, updateAppearance }, { t }, tabs, appearance, getStoredAppearance(), handleSystemThemeChange(), initializeTheme(), mediaQuery() (+28 more)

### Community 33 - "DomainException"

Cohesion: 0.08
Nodes (11): HandleAppearance, AiPlatformException, BackgroundCheckException, DomainException, LogContext, SetLocale, OnboardingException, {closure#1}() (+3 more)

### Community 34 - "Illuminate\Database\Eloquent\Model"

Cohesion: 0.06
Nodes (13): AiRequest, BelongsToTeam, {closure#1}(), {closure#2}(), AuditLog, SourcedValueMap, SourcedValue, {closure#1}() (+5 more)

### Community 35 - "Tests: AiPlatform"

Cohesion: 0.19
Nodes (16): FakeAiTransport, bootAi(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}() (+8 more)

### Community 36 - "Module: AiPlatform"

Cohesion: 0.10
Nodes (6): RegisteredPromptAgent, SchemaConverter, cannedResponses(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 37 - "Tests: Settings"

Cohesion: 0.10
Nodes (6): RegistrationTest, DashboardTest, ExampleTest, ProfileUpdateTest, TestCase, ExampleTest

### Community 38 - "Market"

Cohesion: 0.22
Nodes (5): {closure#1}(), Market, Uae, Uk, {closure#4}()

### Community 39 - "Tests: Enrichment"

Cohesion: 0.10
Nodes (22): {closure#3}(), LeadDeduplicator, Lead, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+14 more)

### Community 40 - "Tests: BusinessIntelligence"

Cohesion: 0.10
Nodes (13): {closure#1}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#18}(), {closure#20}(), {closure#22}(), {closure#24}() (+5 more)

### Community 41 - "AiTransport"

Cohesion: 0.20
Nodes (4): AiTransport, AiPromptRequest, AiTransportResult, LaravelAiTransport

### Community 42 - "App\Modules\ICP\Models\Icp"

Cohesion: 0.07
Nodes (18): InvalidAiResponseException, {closure#3}(), {closure#1}(), {closure#3}(), {closure#3}(), {closure#1}(), {closure#1}(), ScoreLeadAction (+10 more)

### Community 43 - "Illuminate\Support\Str"

Cohesion: 0.07
Nodes (12): {closure#2}(), {closure#4}(), EnrichLeadAction, {closure#1}(), {closure#1}(), CompanyNormaliser, {closure#1}(), {closure#1}() (+4 more)

### Community 44 - "Controller"

Cohesion: 0.09
Nodes (11): Controller, ProfileController, SecurityController, IssueApiTokenAction, SwitchTeamAction, UserController, InvitationAcceptanceController, ApiTokenController (+3 more)

### Community 45 - "Module: ICP"

Cohesion: 0.13
Nodes (13): CriterionData, CriterionType, BuyingSignal, CompanySize, Exclusion, Geography, Industry, JobTitle (+5 more)

### Community 46 - "App\Modules\Core\Models\Team"

Cohesion: 0.20
Nodes (3): {closure#1}(), DiscoveryController, DiscoveryController

### Community 47 - "Frontend: components/ui"

Cohesion: 0.09
Nodes (15): SidebarMenuButtonVariants, delegatedProps, { isMobile, state }, props, props, SidebarMenuButtonProps, emits, forwarded (+7 more)

### Community 48 - "Module: LeadSourcing"

Cohesion: 0.13
Nodes (9): SourcedLead, GooglePlacesProvider, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+1 more)

### Community 49 - "EmailVerificationStatus"

Cohesion: 0.15
Nodes (8): EmailVerificationResult, {closure#1}(), EmailVerificationStatus, DisposableDomain, InvalidSyntax, MxFound, NoMxRecord, Unknown

### Community 50 - "ScoreGrade"

Cohesion: 0.09
Nodes (16): {closure#1}(), ScoreGrade, A, B, C, D, {closure#2}(), LeadScoreFactory (+8 more)

### Community 51 - "CompaniesHouseProviderTest.php"

Cohesion: 0.22
Nodes (7): chQuery(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#6}(), {closure#8}(), {closure#9}()

### Community 52 - "Frontend: components/ui"

Cohesion: 0.09
Nodes (15): emits, forwarded, props, props, delegatedProps, props, props, props (+7 more)

### Community 53 - "Tests: Onboarding"

Cohesion: 0.11
Nodes (23): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#1}() (+15 more)

### Community 54 - "Module: ModuleServiceProvider.php"

Cohesion: 0.16
Nodes (7): AiPlatformServiceProvider, AuthServiceProvider, BusinessIntelligenceServiceProvider, EnrichmentServiceProvider, IcpServiceProvider, ModuleServiceProvider, OnboardingServiceProvider

### Community 56 - "CircuitBreaker"

Cohesion: 0.09
Nodes (12): {closure#1}(), HttpWebContentFetcher, {closure#1}(), {closure#2}(), {closure#3}(), AbstractHttpLeadSourceProvider, {closure#1}(), CircuitBreaker (+4 more)

### Community 57 - "Module: AiPlatform"

Cohesion: 0.12
Nodes (5): {closure#1}(), PromptInterpolator, UntrustedContent, {closure#1}(), {closure#5}()

### Community 58 - "package.json"

Cohesion: 0.04
Nodes (65): dependencies, class-variance-authority@^0.7.1, clsx@^2.1.1, @inertiajs/vite@^3.0.0, @inertiajs/vue3@^3.0.0, laravel-echo@^2.6.3, @laravel/passkeys@^0.2.0, laravel-vite-plugin@^3.0.0 (+57 more)

### Community 59 - "Frontend: components/ui"

Cohesion: 0.10
Nodes (16): vue-input-otp@^0.3.2, vue-input-otp, delegatedProps, emits, forwarded, props, delegatedProps, forwarded (+8 more)

### Community 60 - "root"

Cohesion: 0.10
Nodes (20): compilerOptions, allowJs, esModuleInterop, forceConsistentCasingInFileNames, isolatedModules, jsx, jsxImportSource, lib (+12 more)

### Community 61 - "Tests: Enrichment"

Cohesion: 0.23
Nodes (16): AiCreditExceededException, bootEnrichment(), {closure#10}(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}() (+8 more)

### Community 62 - "BusinessProfile"

Cohesion: 0.10
Nodes (14): {closure#1}(), {closure#2}(), {closure#1}(), GetOnboardingStateAction, {closure#1}(), {closure#2}(), SaveOnboardingStepAction, OnboardingController (+6 more)

### Community 63 - "Tests: Core"

Cohesion: 0.13
Nodes (6): {closure#1}(), Locale, {closure#11}(), {closure#4}(), {closure#5}(), {closure#9}()

### Community 64 - "CreateTeamData"

Cohesion: 0.14
Nodes (6): CreateTeamAction, {closure#1}(), UpdateTeamAction, CreateTeamData, TeamController, CreateTeamRequest

### Community 65 - "Module: BusinessIntelligence"

Cohesion: 0.13
Nodes (4): BackgroundCheckProgressed, EnrichDiscoveredLeads, DiscoveryRunCompleted, {closure#13}()

### Community 67 - "ScoreLeadActionTest.php"

Cohesion: 0.13
Nodes (21): LeadScore, {closure#10}(), {closure#12}(), {closure#14}(), {closure#16}(), {closure#18}(), {closure#19}(), {closure#21}() (+13 more)

### Community 68 - "ScoringWebTest.php"

Cohesion: 0.11
Nodes (17): {closure#11}(), {closure#12}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#18}(), {closure#19}(), {closure#20}() (+9 more)

### Community 69 - "Module: BusinessIntelligence"

Cohesion: 0.12
Nodes (11): BackgroundCheckStatus, Accepted, Failed, Pending, Ready, {closure#1}(), {closure#2}(), {closure#3}() (+3 more)

### Community 70 - "ScoringTenancyTest.php"

Cohesion: 0.12
Nodes (10): {closure#10}(), {closure#11}(), {closure#14}(), {closure#17}(), {closure#19}(), {closure#2}(), {closure#4}(), {closure#8}() (+2 more)

### Community 71 - "Illuminate\Database\Eloquent\Factories\Factory"

Cohesion: 0.06
Nodes (9): LeadContact, IcpFactory, LeadContactFactory, LeadFactory, TeamFactory, UserFactory, {closure#2}(), {closure#3}() (+1 more)

### Community 72 - "Frontend: components/ui"

Cohesion: 0.16
Nodes (15): emits, isMobile, open, openMobile, props, setOpen(), setOpenMobile(), state (+7 more)

### Community 73 - "DiscoverLeadsJob"

Cohesion: 0.07
Nodes (29): {closure#1}(), DiscoveryRunStatus, Completed, Failed, Pending, Running, DiscoveryRunProgressed, {closure#1}() (+21 more)

### Community 74 - "root"

Cohesion: 0.12
Nodes (17): require, inertiajs/inertia-laravel, laravel/ai, laravel/chisel, laravel/fortify, laravel/framework, laravel/horizon, laravel/pennant (+9 more)

### Community 75 - "Illuminate\Foundation\Http\FormRequest"

Cohesion: 0.11
Nodes (6): CreateApiTokenRequest, InviteMemberRequest, UpdateMemberRoleRequest, UpdateTeamRequest, UpdateLocaleRequest, StartDiscoveryRequest

### Community 77 - "@inertiajs/vue3"

Cohesion: 0.12
Nodes (7): Props, Props, Props, { isCurrentOrParentUrl }, sidebarNavItems, Props, Passkey

### Community 78 - "root"

Cohesion: 0.12
Nodes (15): aliases, components, composables, lib, ui, utils, iconLibrary, $schema (+7 more)

### Community 79 - "ScoreLeadJob"

Cohesion: 0.12
Nodes (12): ScoreLeadJob, {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#5}(), {closure#6}() (+4 more)

### Community 80 - "Tests: BusinessIntelligence"

Cohesion: 0.17
Nodes (13): WebContentFetcher, FakeWebContentFetcher, bootBackgroundCheck(), {closure#10}(), {closure#12}(), {closure#3}(), {closure#4}(), {closure#5}() (+5 more)

### Community 81 - "DiscoveryQuery"

Cohesion: 0.09
Nodes (12): DiscoveryQuery, WebSearchProvider, {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#7}() (+4 more)

### Community 82 - "App\Models\User"

Cohesion: 0.07
Nodes (7): {closure#1}(), LeadPolicy, {closure#1}(), {closure#2}(), ScoreFeedback, LeadScorePolicy, ScoreFeedbackFactory

### Community 83 - "app"

Cohesion: 0.14
Nodes (9): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#8}() (+1 more)

### Community 84 - "Database: migrations"

Cohesion: 0.15
Nodes (11): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}() (+3 more)

### Community 85 - "Frontend: components/ui"

Cohesion: 0.22
Nodes (7): delegatedProps, emits, forwarded, props, SheetContentProps, delegatedProps, props

### Community 86 - "Review.vue"

Cohesion: 0.10
Nodes (14): acceptForm, confidencePct, findings, isRunning, page, progress, props, { t } (+6 more)

### Community 88 - "Module: Core"

Cohesion: 0.15
Nodes (7): {closure#1}(), Currency, Aed, Eur, Gbp, Usd, {closure#5}()

### Community 89 - "Database: factories"

Cohesion: 0.12
Nodes (9): {closure#2}(), IcpCriterion, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#1}() (+1 more)

### Community 90 - "root"

Cohesion: 0.14
Nodes (14): scripts, analyse, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd (+6 more)

### Community 91 - "AlertError.vue"

Cohesion: 0.18
Nodes (5): Props, uniqueErrors, props, props, AlertVariants

### Community 92 - ".callCount"

Cohesion: 0.25
Nodes (18): bootScoring(), {closure#11}(), {closure#13}(), {closure#15}(), {closure#17}(), {closure#20}(), {closure#23}(), {closure#27}() (+10 more)

### Community 93 - "Tests: LeadSourcing"

Cohesion: 0.18
Nodes (10): {closure#10}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+2 more)

### Community 94 - "Tests: LeadSourcing"

Cohesion: 0.22
Nodes (7): {closure#10}(), {closure#2}(), {closure#3}(), {closure#7}(), {closure#9}(), fakePlaces(), placesQuery()

### Community 95 - "CriterionScore"

Cohesion: 0.15
Nodes (8): {closure#1}(), CriterionScoreList, CriterionScore, {closure#1}(), {closure#4}(), {closure#4}(), {closure#18}(), {closure#3}()

### Community 97 - "Module: Auth"

Cohesion: 0.23
Nodes (4): {closure#2}(), {closure#2}(), TeamInvitationMail, InvitationUrl

### Community 98 - "DeterministicScorerTest.php"

Cohesion: 0.30
Nodes (17): {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#16}(), {closure#17}(), {closure#19}() (+9 more)

### Community 99 - "Tests: Enrichment"

Cohesion: 0.31
Nodes (7): FakeDnsResolver, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), verifier()

### Community 100 - "root"

Cohesion: 0.15
Nodes (13): require-dev, fakerphp/faker, larastan/larastan, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail (+5 more)

### Community 101 - "AiTier"

Cohesion: 0.16
Nodes (6): AiClient, StructuredAiResponse, AiTier, Cheap, Reasoning, {closure#1}()

### Community 102 - "app"

Cohesion: 0.14
Nodes (6): CreateNewUser, ResetUserPassword, PasswordValidationRules, PasswordUpdateRequest, ProfileDeleteRequest, CreatePersonalTeamAction

### Community 103 - "Module: BusinessIntelligence"

Cohesion: 0.24
Nodes (4): BackgroundCheckFindings, {closure#1}(), {closure#2}(), {closure#3}()

### Community 105 - "Module: Enrichment"

Cohesion: 0.29
Nodes (4): ExtractedFacts, {closure#1}(), {closure#2}(), {closure#3}()

### Community 106 - "Inertia\Testing\AssertableInertia"

Cohesion: 0.16
Nodes (7): {closure#1}(), PasswordConfirmationTest, {closure#1}(), TwoFactorChallengeTest, {closure#10}(), {closure#1}(), {closure#2}()

### Community 107 - "ScoringServiceProvider.php"

Cohesion: 0.17
Nodes (5): {closure#3}(), LeadEnriched, ScoreEnrichedLead, {closure#1}(), {closure#1}()

### Community 109 - "Module: Onboarding"

Cohesion: 0.18
Nodes (8): {closure#1}(), CompanySize, Enterprise, Large, Medium, Micro, Small, {closure#2}()

### Community 110 - "root"

Cohesion: 0.17
Nodes (11): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+3 more)

### Community 111 - "Infra: .github"

Cohesion: 0.17
Nodes (11): default-days, patterns, github-actions, interval, updates, cooldown, directory, groups (+3 more)

### Community 112 - ".first"

Cohesion: 0.33
Nodes (4): {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}()

### Community 115 - "CriterionMatch"

Cohesion: 0.15
Nodes (6): {closure#1}(), CriterionMatch, Matched, NotMatched, Unknown, {closure#2}()

### Community 117 - "Database: seeders"

Cohesion: 0.25
Nodes (3): AiPromptTemplateSeeder, DatabaseSeeder, TeamRolesSeeder

### Community 119 - "App\Modules\Core\Support\TeamContext"

Cohesion: 0.20
Nodes (3): FindDiscoveryRunAction, {closure#1}(), RecordScoreFeedbackAction

### Community 120 - "Tests: Auth"

Cohesion: 0.29
Nodes (10): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+2 more)

### Community 121 - "Web/ScoreController.php"

Cohesion: 0.45
Nodes (3): FindActiveIcpAction, FindScoredLeadAction, ScoreController

### Community 122 - "root"

Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+2 more)

### Community 123 - "Spatie\LaravelData\Data"

Cohesion: 0.06
Nodes (12): AcceptBackgroundCheckData, LeadQuotaSource, {closure#2}(), {closure#3}(), LeadQuota, DiscoveryLimitGuard, StubLeadQuotaSource, assertDiscoveryAllowed() (+4 more)

### Community 124 - "resources"

Cohesion: 0.20
Nodes (9): pulse.cache, pulse.exceptions, pulse.queues, pulse.servers, pulse.slow-jobs, pulse.slow-outgoing-requests, pulse.slow-queries, pulse.slow-requests (+1 more)

### Community 125 - "Tests: AiPlatform"

Cohesion: 0.13
Nodes (4): UnknownPromptException, CostCalculator, PromptRegistry, {closure#1}()

### Community 126 - "ServedRegion"

Cohesion: 0.22
Nodes (6): {closure#1}(), ServedRegion, Other, Uae, Uk, {closure#3}()

### Community 131 - "providers.php"

Cohesion: 0.14
Nodes (4): CoreServiceProvider, LeadSourcingServiceProvider, ScoringServiceProvider, HorizonServiceProvider

### Community 132 - "FeedbackRating"

Cohesion: 0.18
Nodes (5): {closure#1}(), FeedbackRating, Bad, Good, RecordScoreFeedbackRequest

### Community 134 - "ApiTokenTest.php"

Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#5}(), {closure#6}()

### Community 135 - "ScoringApiTest.php"

Cohesion: 0.27
Nodes (8): apiScoredLead(), {closure#10}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#8}(), {closure#9}()

### Community 137 - "App\Modules\ICP\Enums\CriterionType"

Cohesion: 0.22
Nodes (4): {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}()

### Community 138 - "Database: migrations"

Cohesion: 0.29
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 141 - "root"

Cohesion: 0.29
Nodes (7): scripts, build, build:ssr, check, check:fix, dev, types:check

### Community 142 - "WizardProgress.vue"

Cohesion: 0.31
Nodes (8): emit, isCompleted(), isReachable(), percent, props, select(), { t }, total

### Community 143 - "ScoreLeadJobTest.php"

Cohesion: 0.31
Nodes (4): bootJobScoring(), {closure#3}(), {closure#4}(), {closure#6}()

### Community 148 - "root"

Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 149 - "root"

Cohesion: 0.40
Nodes (5): extra, laravel, post-create-project, dont-discover, installer

### Community 163 - "badge/index.ts"

Cohesion: 0.50
Nodes (3): delegatedProps, props, BadgeVariants

### Community 175 - "Docs: ai-prompts.md"

Cohesion: 0.50
Nodes (4): Prompt injection safety (data, not instructions), AI safety (content is data, never instructions), Scraping / acquisition ethics (ToS, robots.txt), robots.txt (allow all)

## Knowledge Gaps

- **109 isolated node(s):** `Matched`, `NotMatched`, `Unknown`, `Good`, `Bad` (+104 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1468 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **82 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions

_Questions this graph is uniquely positioned to answer:_

- **Why does `Team` connect `Auth & Teams (domain)` to `ICP Profiles`, `Illuminate\Http\Request`, `Business Intelligence`, `BusinessProfileFactory.php`, `TeamInvitation`, `StartDiscoveryRunAction.php`, `TeamContext`, `ICP Tests`, `AI Platform (AiClient)`, `HandleInertiaRequests.php`, `EnrichLeadJob`, `OnboardingStep`, `Module: AiPlatform`, `Tests: Core`, `Tests: LeadSourcing`, `DomainException`, `Illuminate\Database\Eloquent\Model`, `Tests: AiPlatform`, `Market`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `Controller`, `App\Modules\Core\Models\Team`, `Tests: Onboarding`, `Module: ModuleServiceProvider.php`, `Module: AiPlatform`, `Tests: Enrichment`, `BusinessProfile`, `Tests: Core`, `CreateTeamData`, `Illuminate\Database\Eloquent\Factories\Factory`, `Tests: BusinessIntelligence`, `Database: factories`, `Database: factories`, `App\Modules\Core\Support\TeamContext`, `Spatie\LaravelData\Data`, `AiRequestFactory.php`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._
- **What connects `Matched`, `NotMatched`, `Unknown` to the rest of the system?**
  _109 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `UI Component Library` be split into smaller, more focused modules?**
  _Cohesion score 0.03576751117734724 - nodes in this community are weakly interconnected._
- **Why does `Lead` connect `Tests: Enrichment` to `Illuminate\Http\Request`, `ScoringApiTest.php`, `App\Modules\ICP\Enums\CriterionType`, `StartDiscoveryRunAction.php`, `ScoreLeadJobTest.php`, `EnrichLeadJob`, `Tests: LeadSourcing`, `Illuminate\Database\Eloquent\Model`, `App\Modules\ICP\Models\Icp`, `Illuminate\Support\Str`, `ScoreGrade`, `CircuitBreaker`, `Tests: Enrichment`, `Module: BusinessIntelligence`, `ScoreLeadActionTest.php`, `ScoringWebTest.php`, `Module: BusinessIntelligence`, `ScoringTenancyTest.php`, `Illuminate\Database\Eloquent\Factories\Factory`, `DiscoverLeadsJob`, `DeterministicScorer`, `Illuminate\Database\Eloquent\Relations\HasMany`, `ScoreLeadJob`, `App\Models\User`, `Database: factories`, `.callCount`, `DeterministicScorerTest.php`, `ScoringServiceProvider.php`, `.feedback`, `App\Modules\Core\Support\TeamContext`, `Web/ScoreController.php`?**
  _High betweenness centrality (0.043) - this node is a cross-community bridge._
- **Should `Auth & Teams (domain)` be split into smaller, more focused modules?**
  _Cohesion score 0.04566544566544566 - nodes in this community are weakly interconnected._
- **Why does `User` connect `Business Intelligence` to `Auth & Teams (domain)`, `Tests: Auth`, `ICP Profiles`, `Illuminate\Http\Request`, `ApiTokenTest.php`, `TeamInvitation`, `app`, `Tests: Auth`, `TeamContext`, `ICP Tests`, `HandleInertiaRequests.php`, `Tests: LeadSourcing`, `DomainException`, `Illuminate\Database\Eloquent\Model`, `Tests: Settings`, `Tests: BusinessIntelligence`, `Controller`, `App\Modules\Core\Models\Team`, `Tests: Onboarding`, `BusinessProfile`, `CreateTeamData`, `Illuminate\Database\Eloquent\Factories\Factory`, `DiscoverLeadsJob`, `App\Models\User`, `Tests: Auth`, `app`, `Inertia\Testing\AssertableInertia`, `Database: factories`, `Tests: Auth`, `Tests: Auth`?**
  _High betweenness centrality (0.040) - this node is a cross-community bridge._
- **Should `Dashboard & App Shell` be split into smaller, more focused modules?**
  _Cohesion score 0.04125263474856971 - nodes in this community are weakly interconnected._
