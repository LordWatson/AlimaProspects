# Graph Report - AlimaProspects (2026-10-29)

## Corpus Check

- 664 files · ~149,759 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 22 file(s) not represented in the graph (top: (none) 17, .example 1, .neon 1)

## Summary

- 3712 nodes · 8414 edges · 203 communities (125 shown, 78 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 375 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness

- Built from commit: `51ca4e62`
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
- AuditLogger
- ICP Tests
- AI Platform (AiClient)
- Lead Discovery (frontend)
- Project Rules (CLAUDE.md)
- UI Components (nav/label)
- CI Workflow
- App Sidebar & Nav
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
- Frontend: composables/useAppearance.ts
- DomainException
- Illuminate\Database\Eloquent\Model
- Tests: AiPlatform
- Module: AiPlatform
- Tests: Settings
- Market
- Tests: Enrichment
- Tests: BusinessIntelligence
- Module: AiPlatform
- App\Modules\ICP\Models\Icp
- Module: Enrichment
- Module: Enrichment
- Module: ICP
- App\Modules\LeadSourcing\Models\DiscoveryRun
- Frontend: components/ui
- Module: LeadSourcing
- Module: Enrichment
- Module: LeadSourcing
- Tests: LeadSourcing
- Frontend: components/ui
- Tests: Onboarding
- Module: ModuleServiceProvider.php
- Module: AiPlatform
- CircuitBreaker
- Module: AiPlatform
- root
- Frontend: components/ui
- root
- Tests: Enrichment
- BusinessProfile
- Tests: Core
- Controller
- Module: BusinessIntelligence
- Module: BusinessIntelligence
- Module: LeadSourcing
- Tests: LeadSourcing
- Module: BusinessIntelligence
- Icp
- Tests: Enrichment
- Frontend: components/ui
- DiscoverLeadsJob
- root
- Illuminate\Foundation\Http\FormRequest
- Module: LeadSourcing
- @inertiajs/vue3
- root
- root
- Tests: BusinessIntelligence
- Tests: LeadSourcing
- User
- app
- Database: migrations
- Frontend: components/ui
- lead-sourcing/Index.vue
- Tests: Auth
- Module: Core
- Database: factories
- root
- Frontend: components/ui
- Frontend: shared/tokens.ts
- Tests: LeadSourcing
- Tests: LeadSourcing
- Tests: LeadSourcing
- AiTier
- Module: Auth
- Module: Enrichment
- Tests: Enrichment
- root
- root
- app
- Module: BusinessIntelligence
- Throwable
- Module: Enrichment
- Inertia\Testing\AssertableInertia
- HasTeams
- app
- Module: Onboarding
- root
- Infra: .github
- .first
- Database: factories
- Database: factories
- Module: LeadSourcing
- Database: seeders
- Tests: Auth
- root
- Tests: Auth
- Module: LeadSourcing
- root
- Tests: Core
- resources
- Tests: AiPlatform
- ServedRegion
- Illuminate\Database\Eloquent\Factories\Factory
- Database: migrations
- Database: migrations
- Tests: Auth
- app
- Frontend: components/ui
- BusinessProfileFactory.php
- CreateTeamData
- app
- app
- ProfileUpdateTest
- Database: migrations
- Tests: Auth
- root
- root
- Frontend: components/onboarding
- .dataValues
- app
- Database: migrations
- Tests: Settings
- SaveOnboardingStepRequest.php
- root
- root
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Checkbox.vue
- app
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
- Database: migrations
- Docs: ai-prompts.md
- Tests: Auth
- root
- Docs: compliance.md
- Docs: brand
- Docs: brand
- Docs: brand
- Docs: modules

## God Nodes (most connected - your core abstractions)

1. `Team` - 176 edges
2. `User` - 147 edges
3. `vue` - 146 edges
4. `TeamContext` - 114 edges
5. `cn()` - 103 edges
6. `createUserWithTeam()` - 97 edges
7. `reka-ui` - 78 edges
8. `AuditLogger` - 62 edges
9. `Lead` - 57 edges
10. `Icp` - 55 edges

## Surprising Connections (you probably didn't know these)

- `{closure#4}()` --calls--> `DiscoveryQuery` [INFERRED]
  tests/Feature/LeadSourcing/DiscoveryGuardsTest.php → app/Modules/LeadSourcing/Data/DiscoveryQuery.php
- `{closure#14}()` --calls--> `StartDiscoveryData` [EXTRACTED]
  tests/Feature/LeadSourcing/StartDiscoveryRunTest.php → app/Modules/LeadSourcing/Data/StartDiscoveryData.php
- `{closure#13}()` --references--> `DiscoveryRun` [EXTRACTED]
  tests/Feature/LeadSourcing/DiscoveryTenancyTest.php → app/Modules/LeadSourcing/Models/DiscoveryRun.php
- `{closure#15}()` --references--> `DiscoveryRun` [EXTRACTED]
  tests/Feature/LeadSourcing/DiscoveryTenancyTest.php → app/Modules/LeadSourcing/Models/DiscoveryRun.php
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

## Communities (203 total, 78 thin omitted)

### Community 0 - "UI Component Library"

Cohesion: 0.04
Nodes (57): vue, props, props, props, props, props, props, props (+49 more)

### Community 1 - "Auth & Teams (domain)"

Cohesion: 0.05
Nodes (27): AcceptInvitationAction, {closure#1}(), {closure#1}(), {closure#1}(), RemoveTeamMemberAction, {closure#1}(), UpdateTeamMemberRoleAction, TeamMembershipException (+19 more)

### Community 2 - "Dashboard & App Shell"

Cohesion: 0.04
Nodes (58): @lucide/vue, { appearance, updateAppearance }, { t }, tabs, ActiveIcon, { appearance, updateAppearance }, options, { t } (+50 more)

### Community 3 - "ICP Profiles"

Cohesion: 0.11
Nodes (13): CreateIcpAction, DeleteIcpAction, FindIcpAction, {closure#1}(), GetIcpsAction, SetActiveIcpAction, UpdateIcpAction, IcpData (+5 more)

### Community 4 - "Illuminate\Http\Request"

Cohesion: 0.04
Nodes (23): ProfileController, HandleAppearance, IssueApiTokenAction, RevokeApiTokenAction, ApiTokenController, UserController, ApiTokenController, {closure#1}() (+15 more)

### Community 5 - "Business Intelligence"

Cohesion: 0.07
Nodes (17): AcceptBackgroundCheckAction, {closure#1}(), {closure#2}(), {closure#1}(), FindReportAction, {closure#1}(), GetLatestReportAction, {closure#1}() (+9 more)

### Community 6 - "Module Docs & ADRs"

Cohesion: 0.06
Nodes (72): ADR 0002 — module structure, ADR 0004 — Fortify + Sanctum + spatie, ADR 0005 — AI via laravel/ai behind AiClient, ADR 0006 — multi-team tenancy, ADR 0007 — UK/UAE markets, bilingual/RTL, compliance, ADR 0008 — lead providers (no paid enrichment MVP), ADR 0010 — web-search provider (Serper), Admin (internal) (+64 more)

### Community 7 - "TeamInvitation"

Cohesion: 0.11
Nodes (10): {closure#1}(), InviteTeamMemberAction, {closure#1}(), {closure#2}(), ResendInvitationAction, InviteMemberData, InvitationException, TeamInvitationController (+2 more)

### Community 8 - "UI Components (forms)"

Cohesion: 0.04
Nodes (45): reka-ui, emits, forwarded, props, props, props, emits, forwarded (+37 more)

### Community 9 - "App Header"

Cohesion: 0.05
Nodes (24): className, Props, auth, { isCurrentUrl, whenCurrentUrl }, mainNavItems, page, Props, rightNavItems (+16 more)

### Community 10 - "StartDiscoveryRunAction.php"

Cohesion: 0.10
Nodes (8): {closure#1}(), {closure#2}(), StartDiscoveryRunAction, LeadInterestGate, LeadSourcingException, AllowAllLeadInterestGate, {closure#21}(), assertDiscoveryAllowed()

### Community 11 - "Auth Pages (frontend)"

Cohesion: 0.05
Nodes (23): @laravel/passkeys, passwordInput, emit, handleDelete(), isDeleting, props, emit, name (+15 more)

### Community 12 - "Onboarding & ICP Builder"

Cohesion: 0.05
Nodes (39): emit, isCompleted(), isReachable(), percent, props, select(), { t }, total (+31 more)

### Community 13 - "AuditLogger"

Cohesion: 0.09
Nodes (8): RevokeInvitationAction, UpdateTeamAction, AuditLogger, {closure#3}(), {closure#2}(), {closure#3}(), {closure#3}(), WritesIcpCriteria

### Community 14 - "ICP Tests"

Cohesion: 0.09
Nodes (36): {closure#3}(), {closure#4}(), IcpUpdated, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}() (+28 more)

### Community 15 - "AI Platform (AiClient)"

Cohesion: 0.16
Nodes (6): AiRequestStatus, Failed, Success, AiPromptTemplate, ClaudeAiClient, {closure#2}()

### Community 16 - "Lead Discovery (frontend)"

Cohesion: 0.05
Nodes (33): acceptForm, confidencePct, findings, isRunning, page, progress, props, { t } (+25 more)

### Community 17 - "Project Rules (CLAUDE.md)"

Cohesion: 0.07
Nodes (28): brand-reviewer agent, laravel-module-reviewer agent, new-module skill, phase-complete skill, quality-gates skill, Changelog, Phase 5 AI Platform (changelog), Phase 6 Business Intelligence (changelog) (+20 more)

### Community 18 - "UI Components (nav/label)"

Cohesion: 0.05
Nodes (35): @vueuse/core, emits, modelValue, props, delegatedProps, props, navigationMenuTriggerStyle, delegatedProps (+27 more)

### Community 19 - "CI Workflow"

Cohesion: 0.05
Nodes (41): env, runs-on, services, steps, DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PASSWORD (+33 more)

### Community 20 - "App Sidebar & Nav"

Cohesion: 0.15
Nodes (12): isActive(), Props, { isCurrentUrl }, currentUrlReactive, page, useCurrentUrl(), isCurrentOrParentUrl(), isCurrentUrl() (+4 more)

### Community 21 - "Two-Factor Setup UI"

Cohesion: 0.06
Nodes (21): { hasSetupData, clearTwoFactorAuthData }, showSetupModal, code, { copy, copied }, isOpen, modalConfig, pinInputContainerRef, Props (+13 more)

### Community 22 - "UI Components (Select)"

Cohesion: 0.05
Nodes (25): emits, forwarded, props, delegatedProps, emits, forwarded, props, props (+17 more)

### Community 23 - "Core: i18n & Localisation"

Cohesion: 0.08
Nodes (14): {closure#1}(), {closure#2}(), HandleInertiaRequests, {closure#1}(), SupportedLocale, Arabic, English, TextDirection (+6 more)

### Community 24 - "EnrichLeadJob"

Cohesion: 0.17
Nodes (8): EnrichLeadJob, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#1}(), {closure#3}()

### Community 25 - "Lead Source Providers"

Cohesion: 0.07
Nodes (18): LeadSourceProvider, {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), LeadSource, CompaniesHouse, GooglePlaces (+10 more)

### Community 26 - "OnboardingStep"

Cohesion: 0.16
Nodes (8): OnboardingStep, Company, Industry, Locations, Offering, Pricing, Review, {closure#1}()

### Community 27 - "Frontend Global Types"

Cohesion: 0.12
Nodes (18): Auth, CurrentTeam, TeamRole, TeamSummary, TwoFactorConfigContent, User, ComponentCustomProperties, GlobalDirectives (+10 more)

### Community 28 - "Settings Pages"

Cohesion: 0.07
Nodes (14): Props, uniqueErrors, isRecoveryCodesVisible, recoveryCodeSectionRef, { recoveryCodesList, fetchRecoveryCodes, errors }, Token, Can, Invitation (+6 more)

### Community 29 - "UI Components (Dialog)"

Cohesion: 0.06
Nodes (23): emits, forwarded, props, props, delegatedProps, emits, forwarded, props (+15 more)

### Community 30 - "Module: AiPlatform"

Cohesion: 0.10
Nodes (7): CreditSource, CreditAllowance, {closure#1}(), AiCreditGuard, AiUsageLogger, PromptRegistry, StubCreditSource

### Community 31 - "Tests: LeadSourcing"

Cohesion: 0.13
Nodes (24): DiscoveryRun, FakeLeadSourceProvider, {closure#4}(), {closure#5}(), bootDiscovery(), {closure#10}(), {closure#11}(), {closure#12}() (+16 more)

### Community 32 - "Frontend: composables/useAppearance.ts"

Cohesion: 0.21
Nodes (14): appearance, getStoredAppearance(), handleSystemThemeChange(), initializeTheme(), mediaQuery(), prefersDark(), setCookie(), updateTheme() (+6 more)

### Community 34 - "Illuminate\Database\Eloquent\Model"

Cohesion: 0.07
Nodes (11): AiRequest, BelongsToTeam, {closure#1}(), {closure#2}(), AuditLog, SourcedValueMap, SourcedValue, OnboardingProgressFactory (+3 more)

### Community 35 - "Tests: AiPlatform"

Cohesion: 0.19
Nodes (16): FakeAiTransport, bootAi(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}() (+8 more)

### Community 37 - "Tests: Settings"

Cohesion: 0.14
Nodes (5): RegistrationTest, DashboardTest, ExampleTest, TestCase, ExampleTest

### Community 38 - "Market"

Cohesion: 0.22
Nodes (5): {closure#1}(), Market, Uae, Uk, {closure#4}()

### Community 39 - "Tests: Enrichment"

Cohesion: 0.15
Nodes (20): Lead, {closure#2}(), {closure#3}(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+12 more)

### Community 40 - "Tests: BusinessIntelligence"

Cohesion: 0.10
Nodes (13): {closure#1}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#18}(), {closure#20}(), {closure#22}(), {closure#24}() (+5 more)

### Community 41 - "Module: AiPlatform"

Cohesion: 0.10
Nodes (9): AiTransport, AiPromptRequest, AiTransportResult, AiCreditExceededException, AiPlatformException, InvalidAiResponseException, UnknownPromptException, LaravelAiTransport (+1 more)

### Community 42 - "App\Modules\ICP\Models\Icp"

Cohesion: 0.15
Nodes (4): {closure#1}(), IcpFactory, {closure#1}(), {closure#12}()

### Community 43 - "Module: Enrichment"

Cohesion: 0.12
Nodes (7): AiClient, {closure#1}(), {closure#2}(), {closure#3}(), EnrichLeadAction, {closure#1}(), CompanyNormaliser

### Community 44 - "Module: Enrichment"

Cohesion: 0.14
Nodes (6): EnrichmentStatus, Enriched, Enriching, Failed, Pending, EnrichDiscoveredLeads

### Community 45 - "Module: ICP"

Cohesion: 0.10
Nodes (15): CriterionData, {closure#1}(), {closure#2}(), CriterionType, BuyingSignal, CompanySize, Exclusion, Geography (+7 more)

### Community 46 - "App\Modules\LeadSourcing\Models\DiscoveryRun"

Cohesion: 0.10
Nodes (6): {closure#1}(), {closure#1}(), StartDiscoveryData, DiscoveryController, DiscoveryController, StartDiscoveryRequest

### Community 47 - "Frontend: components/ui"

Cohesion: 0.09
Nodes (15): SidebarMenuButtonVariants, delegatedProps, { isMobile, state }, props, props, SidebarMenuButtonProps, emits, forwarded (+7 more)

### Community 48 - "Module: LeadSourcing"

Cohesion: 0.23
Nodes (8): SourcedLead, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), sourcedLead()

### Community 49 - "Module: Enrichment"

Cohesion: 0.09
Nodes (11): DnsResolver, EmailVerificationResult, {closure#1}(), EmailVerificationStatus, DisposableDomain, InvalidSyntax, MxFound, NoMxRecord (+3 more)

### Community 50 - "Module: LeadSourcing"

Cohesion: 0.21
Nodes (5): LeadQuotaSource, LeadQuota, {closure#3}(), DiscoveryLimitGuard, StubLeadQuotaSource

### Community 51 - "Tests: LeadSourcing"

Cohesion: 0.12
Nodes (10): DiscoveryQuery, chQuery(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#6}(), {closure#8}(), {closure#9}() (+2 more)

### Community 52 - "Frontend: components/ui"

Cohesion: 0.09
Nodes (15): emits, forwarded, props, props, delegatedProps, props, props, props (+7 more)

### Community 53 - "Tests: Onboarding"

Cohesion: 0.10
Nodes (15): {closure#1}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#17}(), {closure#2}(), {closure#20}(), {closure#21}() (+7 more)

### Community 54 - "Module: ModuleServiceProvider.php"

Cohesion: 0.16
Nodes (8): AiPlatformServiceProvider, AuthServiceProvider, BusinessIntelligenceServiceProvider, CoreServiceProvider, EnrichmentServiceProvider, IcpServiceProvider, ModuleServiceProvider, OnboardingServiceProvider

### Community 55 - "Module: AiPlatform"

Cohesion: 0.08
Nodes (7): JsonSchemaValidator, SchemaConverter, AiPromptTemplateSeeder, cannedResponses(), {closure#3}(), {closure#4}(), {closure#1}()

### Community 56 - "CircuitBreaker"

Cohesion: 0.10
Nodes (9): {closure#1}(), HttpWebContentFetcher, {closure#1}(), AbstractHttpLeadSourceProvider, CircuitBreaker, breaker(), {closure#1}(), {closure#2}() (+1 more)

### Community 57 - "Module: AiPlatform"

Cohesion: 0.12
Nodes (5): {closure#1}(), PromptInterpolator, UntrustedContent, {closure#1}(), {closure#5}()

### Community 58 - "root"

Cohesion: 0.13
Nodes (20): optionalDependencies, @laravel/multiplex@^0.4.1, lightningcss-linux-x64-gnu@^1.29.1, lightningcss-win32-x64-msvc@^1.29.1, @rollup/rollup-linux-x64-gnu@4.9.5, @rollup/rollup-win32-x64-msvc@4.9.5, @tailwindcss/oxide-linux-x64-gnu@^4.0.1, @tailwindcss/oxide-win32-x64-msvc@^4.0.1 (+12 more)

### Community 59 - "Frontend: components/ui"

Cohesion: 0.10
Nodes (16): vue-input-otp@^0.3.2, vue-input-otp, delegatedProps, emits, forwarded, props, delegatedProps, forwarded (+8 more)

### Community 60 - "root"

Cohesion: 0.10
Nodes (20): compilerOptions, allowJs, esModuleInterop, forceConsistentCasingInFileNames, isolatedModules, jsx, jsxImportSource, lib (+12 more)

### Community 61 - "Tests: Enrichment"

Cohesion: 0.22
Nodes (14): WebContentFetcher, FakeWebContentFetcher, bootEnrichment(), {closure#10}(), {closure#11}(), {closure#4}(), {closure#5}(), {closure#6}() (+6 more)

### Community 62 - "BusinessProfile"

Cohesion: 0.10
Nodes (13): {closure#1}(), {closure#2}(), CompleteOnboardingAction, {closure#1}(), GetOnboardingStateAction, {closure#1}(), {closure#2}(), SaveOnboardingStepAction (+5 more)

### Community 63 - "Tests: Core"

Cohesion: 0.18
Nodes (3): {closure#4}(), {closure#5}(), {closure#9}()

### Community 64 - "Controller"

Cohesion: 0.09
Nodes (12): Controller, CreateTeamAction, SwitchTeamAction, TeamController, TeamSwitchController, InvitationAcceptanceController, TeamController, TeamSettingsController (+4 more)

### Community 65 - "Module: BusinessIntelligence"

Cohesion: 0.14
Nodes (6): BackgroundCheckProgressed, DiscoveryRunCompleted, DiscoveryRunProgressed, {closure#13}(), {closure#14}(), {closure#22}()

### Community 67 - "Module: LeadSourcing"

Cohesion: 0.11
Nodes (3): AcceptBackgroundCheckData, {closure#2}(), {closure#3}()

### Community 68 - "Tests: LeadSourcing"

Cohesion: 0.11
Nodes (5): {closure#12}(), {closure#13}(), {closure#15}(), {closure#16}(), {closure#8}()

### Community 69 - "Module: BusinessIntelligence"

Cohesion: 0.13
Nodes (11): BackgroundCheckStatus, Accepted, Failed, Pending, Ready, {closure#1}(), {closure#2}(), {closure#3}() (+3 more)

### Community 70 - "Icp"

Cohesion: 0.08
Nodes (20): {closure#2}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}() (+12 more)

### Community 71 - "Tests: Enrichment"

Cohesion: 0.14
Nodes (5): LeadContact, LeadContactFactory, {closure#2}(), {closure#3}(), {closure#7}()

### Community 72 - "Frontend: components/ui"

Cohesion: 0.16
Nodes (15): emits, isMobile, open, openMobile, props, setOpen(), setOpenMobile(), state (+7 more)

### Community 73 - "DiscoverLeadsJob"

Cohesion: 0.09
Nodes (16): {closure#1}(), DiscoveryRunStatus, Completed, Failed, Pending, Running, {closure#1}(), {closure#2}() (+8 more)

### Community 74 - "root"

Cohesion: 0.12
Nodes (17): require, inertiajs/inertia-laravel, laravel/ai, laravel/chisel, laravel/fortify, laravel/framework, laravel/horizon, laravel/pennant (+9 more)

### Community 75 - "Illuminate\Foundation\Http\FormRequest"

Cohesion: 0.09
Nodes (7): CreateApiTokenRequest, InviteMemberRequest, IssueApiTokenRequest, UpdateMemberRoleRequest, UpdateTeamRequest, AcceptBackgroundCheckRequest, UpdateLocaleRequest

### Community 76 - "Module: LeadSourcing"

Cohesion: 0.11
Nodes (8): {closure#1}(), {closure#1}(), {closure#3}(), {closure#1}(), LeadStatus, Discovered, Enriched, Scored

### Community 77 - "@inertiajs/vue3"

Cohesion: 0.09
Nodes (14): vue-sonner@^2.0.0, @inertiajs/vue3, vue-sonner, Props, Props, Props, applyFromPage(), initializeLocaleDirection() (+6 more)

### Community 78 - "root"

Cohesion: 0.12
Nodes (15): aliases, components, composables, lib, ui, utils, iconLibrary, $schema (+7 more)

### Community 79 - "root"

Cohesion: 0.12
Nodes (16): dependencies, class-variance-authority@^0.7.1, clsx@^2.1.1, @inertiajs/vue3@^3.0.0, @laravel/passkeys@^0.2.0, @lucide/vue@^1.17.0, reka-ui@^2.9.8, tailwind-merge@^3.2.0 (+8 more)

### Community 80 - "Tests: BusinessIntelligence"

Cohesion: 0.28
Nodes (11): bootBackgroundCheck(), {closure#10}(), {closure#12}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}() (+3 more)

### Community 81 - "Tests: LeadSourcing"

Cohesion: 0.18
Nodes (8): {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#7}(), {closure#9}(), webQuery()

### Community 82 - "User"

Cohesion: 0.12
Nodes (5): User, {closure#1}(), TeamPolicy, DiscoveryRunPolicy, {closure#2}()

### Community 83 - "app"

Cohesion: 0.13
Nodes (9): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#8}() (+1 more)

### Community 84 - "Database: migrations"

Cohesion: 0.21
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 85 - "Frontend: components/ui"

Cohesion: 0.22
Nodes (7): delegatedProps, emits, forwarded, props, SheetContentProps, delegatedProps, props

### Community 86 - "lead-sourcing/Index.vue"

Cohesion: 0.10
Nodes (11): activeIcp, form, isRunning(), liveProgress, page, props, statusFor(), statusLabel (+3 more)

### Community 88 - "Module: Core"

Cohesion: 0.15
Nodes (7): {closure#1}(), Currency, Aed, Eur, Gbp, Usd, {closure#5}()

### Community 89 - "Database: factories"

Cohesion: 0.19
Nodes (4): {closure#2}(), IcpCriterion, {closure#1}(), IcpCriterionFactory

### Community 90 - "root"

Cohesion: 0.14
Nodes (14): scripts, analyse, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd (+6 more)

### Community 91 - "Frontend: components/ui"

Cohesion: 0.16
Nodes (8): class-variance-authority, props, props, props, AlertVariants, delegatedProps, props, BadgeVariants

### Community 92 - "Frontend: shared/tokens.ts"

Cohesion: 0.14
Nodes (13): brand, BrandLocale, fonts, grid, motion, palette, proportion, radius (+5 more)

### Community 93 - "Tests: LeadSourcing"

Cohesion: 0.13
Nodes (11): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#5}(), {closure#6}(), {closure#7}() (+3 more)

### Community 94 - "Tests: LeadSourcing"

Cohesion: 0.22
Nodes (7): {closure#10}(), {closure#2}(), {closure#3}(), {closure#7}(), {closure#9}(), fakePlaces(), placesQuery()

### Community 95 - "Tests: LeadSourcing"

Cohesion: 0.27
Nodes (11): {closure#10}(), {closure#11}(), {closure#13}(), {closure#14}(), {closure#2}(), {closure#4}(), {closure#6}(), {closure#7}() (+3 more)

### Community 96 - "AiTier"

Cohesion: 0.09
Nodes (5): StructuredAiResponse, AiTier, Cheap, Reasoning, AiPromptTemplateFactory

### Community 97 - "Module: Auth"

Cohesion: 0.23
Nodes (3): {closure#2}(), TeamInvitationMail, InvitationUrl

### Community 99 - "Tests: Enrichment"

Cohesion: 0.31
Nodes (7): FakeDnsResolver, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), verifier()

### Community 100 - "root"

Cohesion: 0.15
Nodes (13): require-dev, fakerphp/faker, larastan/larastan, laravel/boost, laravel/pail, laravel/pao, laravel/pint, laravel/sail (+5 more)

### Community 101 - "root"

Cohesion: 0.15
Nodes (13): devDependencies, concurrently@^10.0.3, @laravel/vite-plugin-wayfinder@^0.1.3, @types/node@^22.13.5, typescript@^5.2.2, vite@npm:@voidzero-dev/vite-plus-core@1.4.0, vue-tsc@^3.3.13, concurrently (+5 more)

### Community 102 - "app"

Cohesion: 0.16
Nodes (4): ResetUserPassword, PasswordValidationRules, PasswordUpdateRequest, ProfileDeleteRequest

### Community 103 - "Module: BusinessIntelligence"

Cohesion: 0.27
Nodes (4): BackgroundCheckFindings, {closure#1}(), {closure#2}(), {closure#3}()

### Community 105 - "Module: Enrichment"

Cohesion: 0.29
Nodes (4): ExtractedFacts, {closure#1}(), {closure#2}(), {closure#3}()

### Community 106 - "Inertia\Testing\AssertableInertia"

Cohesion: 0.23
Nodes (6): {closure#1}(), PasswordConfirmationTest, {closure#1}(), {closure#10}(), {closure#1}(), {closure#2}()

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

Cohesion: 0.22
Nodes (5): {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), {closure#2}()

### Community 119 - "root"

Cohesion: 0.18
Nodes (10): @inertiajs/vite@^3.0.0, laravel-vite-plugin@^3.0.0, @tailwindcss/vite@^4.1.11, vite-plus@1.4.0, @vitejs/plugin-vue@^6.0.0, @inertiajs/vite, laravel-vite-plugin, @tailwindcss/vite (+2 more)

### Community 120 - "Tests: Auth"

Cohesion: 0.29
Nodes (10): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+2 more)

### Community 122 - "root"

Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+2 more)

### Community 123 - "Tests: Core"

Cohesion: 0.12
Nodes (4): {closure#1}(), {closure#2}(), {closure#4}(), quotaFor()

### Community 124 - "resources"

Cohesion: 0.20
Nodes (9): pulse.cache, pulse.exceptions, pulse.queues, pulse.servers, pulse.slow-jobs, pulse.slow-outgoing-requests, pulse.slow-queries, pulse.slow-requests (+1 more)

### Community 126 - "ServedRegion"

Cohesion: 0.22
Nodes (6): {closure#1}(), ServedRegion, Other, Uae, Uk, {closure#3}()

### Community 127 - "Illuminate\Database\Eloquent\Factories\Factory"

Cohesion: 0.11
Nodes (4): AiRequestFactory, LeadFactory, TeamFactory, UserFactory

### Community 132 - "Frontend: components/ui"

Cohesion: 0.25
Nodes (4): props, delegatedProps, props, props

### Community 134 - "CreateTeamData"

Cohesion: 0.12
Nodes (12): CreateTeamData, {closure#1}(), {closure#2}(), {closure#5}(), {closure#6}(), {closure#1}(), {closure#2}(), {closure#3}() (+4 more)

### Community 135 - "app"

Cohesion: 0.29
Nodes (3): {closure#1}(), CreateNewUser, CreatePersonalTeamAction

### Community 138 - "Database: migrations"

Cohesion: 0.29
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 140 - "root"

Cohesion: 0.29
Nodes (6): laravel-echo@^2.6.3, pusher-js@^8.6.0, laravel-echo, pusher-js, initializeEcho(), Window

### Community 141 - "root"

Cohesion: 0.29
Nodes (7): scripts, build, build:ssr, check, check:fix, dev, types:check

### Community 142 - "Frontend: components/onboarding"

Cohesion: 0.38
Nodes (6): add(), draft, emit, props, remove(), { t }

### Community 143 - ".dataValues"

Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#1}(), {closure#2}()

### Community 145 - "Database: migrations"

Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 148 - "root"

Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 149 - "root"

Cohesion: 0.40
Nodes (5): extra, laravel, post-create-project, dont-discover, installer

### Community 157 - "Checkbox.vue"

Cohesion: 0.40
Nodes (4): delegatedProps, emits, forwarded, props

### Community 175 - "Docs: ai-prompts.md"

Cohesion: 0.50
Nodes (4): Prompt injection safety (data, not instructions), AI safety (content is data, never instructions), Scraping / acquisition ethics (ToS, robots.txt), robots.txt (allow all)

## Knowledge Gaps

- **100 isolated node(s):** `Admin`, `Member`, `Owner`, `ReadOnly`, `Enterprise` (+95 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1367 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **78 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions

_Questions this graph is uniquely positioned to answer:_

- **Why does `Team` connect `Auth & Teams (domain)` to `ICP Profiles`, `Illuminate\Http\Request`, `Business Intelligence`, `BusinessProfileFactory.php`, `TeamInvitation`, `app`, `CreateTeamData`, `StartDiscoveryRunAction.php`, `AuditLogger`, `ICP Tests`, `AI Platform (AiClient)`, `.dataValues`, `Core: i18n & Localisation`, `EnrichLeadJob`, `Module: AiPlatform`, `Tests: Core`, `Tests: LeadSourcing`, `Illuminate\Database\Eloquent\Model`, `Tests: AiPlatform`, `Market`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `Module: AiPlatform`, `App\Modules\ICP\Models\Icp`, `Module: ICP`, `App\Modules\LeadSourcing\Models\DiscoveryRun`, `Module: LeadSourcing`, `Tests: Onboarding`, `Module: ModuleServiceProvider.php`, `Tests: Enrichment`, `BusinessProfile`, `Tests: Core`, `Controller`, `Module: LeadSourcing`, `Tests: LeadSourcing`, `Icp`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `User`, `AiTier`, `HasTeams`, `Database: factories`, `Database: factories`, `Database: seeders`, `Illuminate\Database\Eloquent\Factories\Factory`?**
  _High betweenness centrality (0.078) - this node is a cross-community bridge._
- **What connects `Admin`, `Member`, `Owner` to the rest of the system?**
  _100 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `UI Component Library` be split into smaller, more focused modules?**
  _Cohesion score 0.03793103448275862 - nodes in this community are weakly interconnected._
- **Why does `User` connect `User` to `Auth & Teams (domain)`, `Tests: Auth`, `ICP Profiles`, `Illuminate\Http\Request`, `Business Intelligence`, `CreateTeamData`, `app`, `TeamInvitation`, `ProfileUpdateTest`, `StartDiscoveryRunAction.php`, `Tests: Auth`, `AuditLogger`, `ICP Tests`, `Core: i18n & Localisation`, `Illuminate\Database\Eloquent\Model`, `Tests: Settings`, `Tests: BusinessIntelligence`, `App\Modules\LeadSourcing\Models\DiscoveryRun`, `Tests: Onboarding`, `BusinessProfile`, `Controller`, `Icp`, `Tests: Auth`, `app`, `Inertia\Testing\AssertableInertia`, `HasTeams`, `Database: factories`, `Tests: Auth`, `Tests: Auth`, `Tests: Auth`, `Illuminate\Database\Eloquent\Factories\Factory`?**
  _High betweenness centrality (0.062) - this node is a cross-community bridge._
- **Should `Auth & Teams (domain)` be split into smaller, more focused modules?**
  _Cohesion score 0.052507836990595615 - nodes in this community are weakly interconnected._
- **Why does `TeamContext` connect `Auth & Teams (domain)` to `ICP Profiles`, `Illuminate\Http\Request`, `Business Intelligence`, `StartDiscoveryRunAction.php`, `AuditLogger`, `ICP Tests`, `AI Platform (AiClient)`, `EnrichLeadJob`, `Module: AiPlatform`, `Tests: Core`, `Tests: LeadSourcing`, `Illuminate\Database\Eloquent\Model`, `Tests: AiPlatform`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `Module: AiPlatform`, `Module: Enrichment`, `Module: Enrichment`, `App\Modules\LeadSourcing\Models\DiscoveryRun`, `Tests: Onboarding`, `Module: ModuleServiceProvider.php`, `Tests: Enrichment`, `BusinessProfile`, `Controller`, `Tests: LeadSourcing`, `Module: BusinessIntelligence`, `Icp`, `Tests: Enrichment`, `DiscoverLeadsJob`, `Tests: BusinessIntelligence`, `Throwable`, `HasTeams`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **Should `Dashboard & App Shell` be split into smaller, more focused modules?**
  _Cohesion score 0.037575757575757575 - nodes in this community are weakly interconnected._
