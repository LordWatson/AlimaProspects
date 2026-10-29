# Graph Report - AlimaProspects  (2026-10-29)

## Corpus Check
- Large corpus: 681 files · ~149,759 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 3705 nodes · 8414 edges · 204 communities (121 shown, 83 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 372 edges (avg confidence: 0.85)
- Token cost: 312,914 input · 0 output

## Community Hubs (Navigation)
- UI Component Library
- Auth & Teams (domain)
- Dashboard & App Shell
- ICP Profiles
- Auth: API Tokens & Teams
- Business Intelligence
- Module Docs & ADRs
- Auth: Profile & Security
- UI Components (forms)
- App Header
- Teams & Settings
- Auth Pages (frontend)
- Onboarding & ICP Builder
- Auth: Invitations & Tokens
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
- BI & Enrichment Jobs
- Lead Source Providers
- Onboarding Steps
- Frontend Global Types
- Settings Pages
- UI Components (Dialog)
- Module: AiPlatform
- Tests: LeadSourcing
- Frontend: composables/useAppearance.ts
- Module: Core
- Module: Core
- Tests: AiPlatform
- Module: AiPlatform
- Tests: Settings
- Module: Onboarding
- Tests: Enrichment
- Tests: BusinessIntelligence
- Module: AiPlatform
- Database: factories
- Module: Enrichment
- Module: Enrichment
- Module: ICP
- Frontend: components/PasskeyItem.vue
- Frontend: components/ui
- Module: LeadSourcing
- Module: Enrichment
- Module: LeadSourcing
- Tests: LeadSourcing
- Frontend: components/ui
- Tests: Onboarding
- Module: ModuleServiceProvider.php
- Module: AiPlatform
- Module: LeadSourcing
- Module: AiPlatform
- root
- Frontend: components/ui
- root
- Tests: Enrichment
- Module: Onboarding
- Tests: Core
- Frontend: pages/business-intel
- Module: BusinessIntelligence
- Module: BusinessIntelligence
- Module: LeadSourcing
- Tests: LeadSourcing
- Module: BusinessIntelligence
- Module: Core
- Tests: Enrichment
- Frontend: components/ui
- Module: LeadSourcing
- root
- Module: Auth
- Module: LeadSourcing
- Module: Onboarding
- root
- root
- Tests: BusinessIntelligence
- Tests: LeadSourcing
- Module: LeadSourcing
- app
- Database: migrations
- Frontend: components/ui
- Database: factories
- Tests: Auth
- Module: Core
- Database: factories
- root
- Frontend: components/ui
- Frontend: shared/tokens.ts
- Tests: LeadSourcing
- Tests: LeadSourcing
- Tests: LeadSourcing
- Database: factories
- Module: Auth
- Module: Enrichment
- Tests: Enrichment
- root
- root
- app
- Module: BusinessIntelligence
- Module: Enrichment
- Module: Enrichment
- Module: LeadSourcing
- Module: LeadSourcing
- app
- Module: Onboarding
- root
- Infra: .github
- Module: Auth
- Database: factories
- Database: factories
- Module: LeadSourcing
- Tests: Auth
- Database: seeders
- Tests: Auth
- root
- Tests: Auth
- Module: LeadSourcing
- root
- resources
- Tests: AiPlatform
- Module: Enrichment
- Database: factories
- Database: migrations
- Database: migrations
- Tests: Auth
- app
- Frontend: components/ui
- Frontend: components/ui
- Tests: Auth
- app
- app
- Module: Enrichment
- Database: migrations
- Tests: Auth
- root
- root
- Frontend: components/onboarding
- Tests: Auth
- app
- Database: migrations
- Tests: Settings
- app
- root
- root
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- Database: migrations
- app
- Module: Auth
- Module: BusinessIntelligence
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
1. `Team` - 193 edges
2. `User` - 153 edges
3. `vue` - 146 edges
4. `TeamContext` - 121 edges
5. `cn()` - 103 edges
6. `createUserWithTeam()` - 97 edges
7. `reka-ui` - 78 edges
8. `AuditLogger` - 65 edges
9. `Icp` - 64 edges
10. `LeadSource` - 61 edges

## Surprising Connections (you probably didn't know these)
- `{closure#3}()` --references--> `TeamInvitationMail`  [EXTRACTED]
  tests/Feature/Auth/TeamInvitationTest.php → app/Modules/Auth/Mail/TeamInvitationMail.php
- `{closure#3}()` --references--> `TeamInvitation`  [EXTRACTED]
  database/seeders/DemoSeeder.php → app/Modules/Auth/Models/TeamInvitation.php
- `{closure#3}()` --references--> `EnrichLeadJob`  [EXTRACTED]
  tests/Feature/Enrichment/EnrichmentTriggerTest.php → app/Modules/Enrichment/Jobs/EnrichLeadJob.php
- `{closure#1}()` --references--> `Icp`  [EXTRACTED]
  tests/Feature/LeadSourcing/StartDiscoveryRunTest.php → app/Modules/ICP/Models/Icp.php
- `{closure#12}()` --references--> `Icp`  [EXTRACTED]
  tests/Feature/LeadSourcing/StartDiscoveryRunTest.php → app/Modules/ICP/Models/Icp.php

## Import Cycles
- 3-file cycle: `resources/js/components/ui/sidebar/SidebarMenuButton.vue -> resources/js/components/ui/sidebar/SidebarMenuButtonChild.vue -> resources/js/components/ui/sidebar/index.ts -> resources/js/components/ui/sidebar/SidebarMenuButton.vue`

## Hyperedges (group relationships)
- **Signal Orange decorative-only rule enforced across docs** — claude_brand_law, docs_brand_brand_tokens_signal_orange_rule, _claude_agents_brand_reviewer_guardian [EXTRACTED 0.90]
- **Phase definition-of-done: gates + reviewers + status table** — _claude_skills_phase_complete_skill_checklist, _claude_skills_quality_gates_skill_gates, _claude_agents_laravel_module_reviewer_reviewer, _claude_agents_brand_reviewer_guardian, mvp_phase_status_table [EXTRACTED 0.85]
- **Scraped/web content treated as data, never instructions** — docs_architecture_ai_safety, docs_ai_prompts_injection_safety, docs_compliance_scraping_ethics [EXTRACTED 0.90]
- **Lead lifecycle: source → enrich → score → workspace → outreach** — docs_modules_lead_sourcing_lead_sourcing, docs_modules_enrichment_data_quality_enrichment_data_quality, docs_modules_scoring_matching_scoring_matching, docs_modules_leads_workspace_leads_workspace, docs_modules_outreach_outreach [EXTRACTED 0.90]
- **AiClient consumers (structured AI across modules)** — docs_modules_ai_platform_aiclient, docs_modules_business_intelligence_business_intelligence, docs_modules_enrichment_data_quality_enrichment_data_quality, docs_modules_scoring_matching_scoring_matching, docs_modules_outreach_outreach, docs_modules_ai_insights_ai_insights [EXTRACTED 0.85]
- **Reverb live progress broadcasting** — docs_modules_notifications_reverb, docs_modules_lead_sourcing_lead_sourcing, docs_modules_business_intelligence_business_intelligence, docs_modules_notifications_notifications [EXTRACTED 0.80]

## Communities (204 total, 83 thin omitted)

### Community 0 - "UI Component Library"
Cohesion: 0.04
Nodes (56): vue, props, props, props, props, props, props, props (+48 more)

### Community 1 - "Auth & Teams (domain)"
Cohesion: 0.04
Nodes (28): {closure#1}(), User, {closure#1}(), {closure#1}(), CreateTeamAction, {closure#1}(), {closure#2}(), RemoveTeamMemberAction (+20 more)

### Community 2 - "Dashboard & App Shell"
Cohesion: 0.04
Nodes (44): @lucide/vue, ActiveIcon, { appearance, updateAppearance }, options, { t }, props, { locale, available, setLocale, t }, { isMobile, state } (+36 more)

### Community 3 - "ICP Profiles"
Cohesion: 0.06
Nodes (29): {closure#1}(), {closure#2}(), {closure#3}(), CreateIcpAction, DeleteIcpAction, {closure#1}(), FindIcpAction, {closure#1}() (+21 more)

### Community 4 - "Auth: API Tokens & Teams"
Cohesion: 0.05
Nodes (19): RevokeApiTokenAction, ApiTokenController, TeamController, TeamSwitchController, UserController, ApiTokenResource, TeamResource, UserResource (+11 more)

### Community 5 - "Business Intelligence"
Cohesion: 0.05
Nodes (20): AcceptBackgroundCheckAction, {closure#1}(), {closure#2}(), {closure#1}(), FindReportAction, {closure#1}(), GetLatestReportAction, {closure#1}() (+12 more)

### Community 6 - "Module Docs & ADRs"
Cohesion: 0.06
Nodes (72): ADR 0002 — module structure, ADR 0004 — Fortify + Sanctum + spatie, ADR 0005 — AI via laravel/ai behind AiClient, ADR 0006 — multi-team tenancy, ADR 0007 — UK/UAE markets, bilingual/RTL, compliance, ADR 0008 — lead providers (no paid enrichment MVP), ADR 0010 — web-search provider (Serper), Admin (internal) (+64 more)

### Community 7 - "Auth: Profile & Security"
Cohesion: 0.06
Nodes (18): Controller, ProfileController, SecurityController, AcceptInvitationAction, {closure#2}(), SwitchTeamAction, InvitationException, InvitationAcceptanceController (+10 more)

### Community 8 - "UI Components (forms)"
Cohesion: 0.04
Nodes (45): reka-ui, emits, forwarded, props, props, props, emits, forwarded (+37 more)

### Community 9 - "App Header"
Cohesion: 0.05
Nodes (24): className, Props, auth, { isCurrentUrl, whenCurrentUrl }, mainNavItems, page, Props, rightNavItems (+16 more)

### Community 10 - "Teams & Settings"
Cohesion: 0.07
Nodes (11): TeamSettingsController, TeamPolicy, HasTeams, Team, {closure#1}(), {closure#2}(), StartDiscoveryRunAction, LeadInterestGate (+3 more)

### Community 11 - "Auth Pages (frontend)"
Cohesion: 0.07
Nodes (16): @laravel/passkeys, emit, name, { register, isLoading, error, isSupported }, showForm, Props, { verify, isLoading, error, isSupported }, inputRef (+8 more)

### Community 12 - "Onboarding & ICP Builder"
Cohesion: 0.05
Nodes (39): emit, isCompleted(), isReachable(), percent, props, select(), { t }, total (+31 more)

### Community 13 - "Auth: Invitations & Tokens"
Cohesion: 0.07
Nodes (16): {closure#1}(), InviteTeamMemberAction, {closure#1}(), IssueApiTokenAction, {closure#1}(), ResendInvitationAction, {closure#1}(), {closure#1}() (+8 more)

### Community 14 - "ICP Tests"
Cohesion: 0.09
Nodes (36): {closure#3}(), {closure#4}(), IcpUpdated, {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#14}() (+28 more)

### Community 15 - "AI Platform (AiClient)"
Cohesion: 0.09
Nodes (13): AiClient, AiTransportResult, StructuredAiResponse, AiRequestStatus, Failed, Success, AiTier, Cheap (+5 more)

### Community 16 - "Lead Discovery (frontend)"
Cohesion: 0.06
Nodes (30): confirmingDelete, props, { t }, activeIcp, form, isRunning(), liveProgress, page (+22 more)

### Community 17 - "Project Rules (CLAUDE.md)"
Cohesion: 0.07
Nodes (28): brand-reviewer agent, laravel-module-reviewer agent, new-module skill, phase-complete skill, quality-gates skill, Changelog, Phase 5 AI Platform (changelog), Phase 6 Business Intelligence (changelog) (+20 more)

### Community 18 - "UI Components (nav/label)"
Cohesion: 0.06
Nodes (32): @vueuse/core, delegatedProps, props, navigationMenuTriggerStyle, delegatedProps, emits, forwarded, props (+24 more)

### Community 19 - "CI Workflow"
Cohesion: 0.05
Nodes (41): env, runs-on, services, steps, DB_CONNECTION, DB_DATABASE, DB_HOST, DB_PASSWORD (+33 more)

### Community 20 - "App Sidebar & Nav"
Cohesion: 0.07
Nodes (25): account, initials, isActive(), { isCurrentUrl, isCurrentOrParentUrl }, Item, page, { t }, user (+17 more)

### Community 21 - "Two-Factor Setup UI"
Cohesion: 0.06
Nodes (21): { hasSetupData, clearTwoFactorAuthData }, showSetupModal, code, { copy, copied }, isOpen, modalConfig, pinInputContainerRef, Props (+13 more)

### Community 22 - "UI Components (Select)"
Cohesion: 0.05
Nodes (25): emits, forwarded, props, delegatedProps, emits, forwarded, props, props (+17 more)

### Community 23 - "Core: i18n & Localisation"
Cohesion: 0.08
Nodes (14): {closure#1}(), {closure#2}(), HandleInertiaRequests, {closure#1}(), SupportedLocale, Arabic, English, TextDirection (+6 more)

### Community 24 - "BI & Enrichment Jobs"
Cohesion: 0.11
Nodes (9): {closure#5}(), RunBackgroundCheckJob, EnrichLeadJob, {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+1 more)

### Community 25 - "Lead Source Providers"
Cohesion: 0.09
Nodes (15): LeadSourceProvider, {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), LeadSource, CompaniesHouse, GooglePlaces (+7 more)

### Community 26 - "Onboarding Steps"
Cohesion: 0.08
Nodes (17): {closure#1}(), {closure#2}(), OnboardingStep, Company, Industry, Locations, Offering, Pricing (+9 more)

### Community 27 - "Frontend Global Types"
Cohesion: 0.07
Nodes (23): Props, Props, Props, Props, Auth, CurrentTeam, Passkey, TeamRole (+15 more)

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
Cohesion: 0.12
Nodes (21): {closure#1}(), DiscoveryRun, DiscoveryRunPolicy, bootDiscovery(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+13 more)

### Community 32 - "Frontend: composables/useAppearance.ts"
Cohesion: 0.11
Nodes (24): vue-sonner@^2.0.0, @inertiajs/vue3, vue-sonner, { appearance, updateAppearance }, { t }, tabs, appearance, getStoredAppearance() (+16 more)

### Community 33 - "Module: Core"
Cohesion: 0.10
Nodes (8): HandleAppearance, DomainException, SetLocale, OnboardingException, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 34 - "Module: Core"
Cohesion: 0.19
Nodes (4): BelongsToTeam, {closure#1}(), {closure#2}(), AuditLog

### Community 35 - "Tests: AiPlatform"
Cohesion: 0.19
Nodes (16): FakeAiTransport, bootAi(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#15}() (+8 more)

### Community 36 - "Module: AiPlatform"
Cohesion: 0.10
Nodes (6): RegisteredPromptAgent, SchemaConverter, cannedResponses(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 37 - "Tests: Settings"
Cohesion: 0.11
Nodes (6): PasswordConfirmationTest, DashboardTest, ExampleTest, ProfileUpdateTest, TestCase, ExampleTest

### Community 38 - "Module: Onboarding"
Cohesion: 0.08
Nodes (12): {closure#1}(), Market, Uae, Uk, {closure#1}(), ServedRegion, Other, Uae (+4 more)

### Community 39 - "Tests: Enrichment"
Cohesion: 0.14
Nodes (20): Lead, {closure#2}(), {closure#3}(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}() (+12 more)

### Community 40 - "Tests: BusinessIntelligence"
Cohesion: 0.10
Nodes (13): {closure#1}(), {closure#12}(), {closure#14}(), {closure#15}(), {closure#18}(), {closure#20}(), {closure#22}(), {closure#24}() (+5 more)

### Community 41 - "Module: AiPlatform"
Cohesion: 0.11
Nodes (7): AiTransport, AiPromptRequest, AiPlatformException, AiTransportException, InvalidAiResponseException, UnknownPromptException, LaravelAiTransport

### Community 42 - "Database: factories"
Cohesion: 0.09
Nodes (4): IcpFactory, OnboardingProgressFactory, TeamFactory, TeamInvitationFactory

### Community 43 - "Module: Enrichment"
Cohesion: 0.12
Nodes (7): WebContentFetcher, FakeWebContentFetcher, {closure#2}(), {closure#3}(), EnrichLeadAction, {closure#1}(), CompanyNormaliser

### Community 44 - "Module: Enrichment"
Cohesion: 0.09
Nodes (11): EnrichmentStatus, Enriched, Enriching, Failed, Pending, EnrichDiscoveredLeads, DiscoveryRunCompleted, {closure#1}() (+3 more)

### Community 45 - "Module: ICP"
Cohesion: 0.11
Nodes (15): CriterionData, {closure#1}(), {closure#2}(), CriterionType, BuyingSignal, CompanySize, Exclusion, Geography (+7 more)

### Community 46 - "Frontend: components/PasskeyItem.vue"
Cohesion: 0.13
Nodes (7): passwordInput, emit, handleDelete(), isDeleting, props, grades, swatches

### Community 47 - "Frontend: components/ui"
Cohesion: 0.09
Nodes (15): SidebarMenuButtonVariants, delegatedProps, { isMobile, state }, props, props, SidebarMenuButtonProps, emits, forwarded (+7 more)

### Community 48 - "Module: LeadSourcing"
Cohesion: 0.13
Nodes (9): AcceptBackgroundCheckData, SourcedLead, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+1 more)

### Community 49 - "Module: Enrichment"
Cohesion: 0.13
Nodes (9): EmailVerificationResult, {closure#1}(), EmailVerificationStatus, DisposableDomain, InvalidSyntax, MxFound, NoMxRecord, Unknown (+1 more)

### Community 50 - "Module: LeadSourcing"
Cohesion: 0.13
Nodes (10): LeadQuotaSource, LeadQuota, {closure#3}(), DiscoveryLimitGuard, StubLeadQuotaSource, assertDiscoveryAllowed(), {closure#1}(), {closure#2}() (+2 more)

### Community 51 - "Tests: LeadSourcing"
Cohesion: 0.13
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

### Community 56 - "Module: LeadSourcing"
Cohesion: 0.17
Nodes (8): {closure#1}(), {closure#2}(), LeadSourcingServiceProvider, CircuitBreaker, breaker(), {closure#1}(), {closure#2}(), {closure#3}()

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
Cohesion: 0.27
Nodes (14): AiCreditExceededException, bootEnrichment(), {closure#10}(), {closure#11}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}() (+6 more)

### Community 62 - "Module: Onboarding"
Cohesion: 0.20
Nodes (4): GetOnboardingStateAction, OnboardingController, OnboardingController, OnboardingStateResource

### Community 63 - "Tests: Core"
Cohesion: 0.13
Nodes (8): {closure#1}(), {closure#1}(), {closure#10}(), {closure#4}(), {closure#5}(), {closure#9}(), {closure#1}(), {closure#2}()

### Community 64 - "Frontend: pages/business-intel"
Cohesion: 0.11
Nodes (14): acceptForm, confidencePct, findings, isRunning, page, progress, props, { t } (+6 more)

### Community 65 - "Module: BusinessIntelligence"
Cohesion: 0.15
Nodes (5): BackgroundCheckProgressed, DiscoveryRunProgressed, {closure#13}(), {closure#14}(), {closure#22}()

### Community 67 - "Module: LeadSourcing"
Cohesion: 0.12
Nodes (3): {closure#2}(), {closure#3}(), CompaniesHouseProvider

### Community 68 - "Tests: LeadSourcing"
Cohesion: 0.11
Nodes (5): {closure#12}(), {closure#13}(), {closure#15}(), {closure#16}(), {closure#8}()

### Community 69 - "Module: BusinessIntelligence"
Cohesion: 0.14
Nodes (10): BackgroundCheckStatus, Accepted, Failed, Pending, Ready, {closure#1}(), {closure#1}(), {closure#2}() (+2 more)

### Community 70 - "Module: Core"
Cohesion: 0.19
Nodes (3): {closure#1}(), HttpWebContentFetcher, AbstractHttpLeadSourceProvider

### Community 71 - "Tests: Enrichment"
Cohesion: 0.14
Nodes (5): LeadContact, LeadContactFactory, {closure#2}(), {closure#3}(), {closure#7}()

### Community 72 - "Frontend: components/ui"
Cohesion: 0.16
Nodes (15): emits, isMobile, open, openMobile, props, setOpen(), setOpenMobile(), state (+7 more)

### Community 73 - "Module: LeadSourcing"
Cohesion: 0.17
Nodes (7): {closure#1}(), {closure#3}(), {closure#6}(), DiscoverLeadsJob, {closure#19}(), {closure#3}(), {closure#5}()

### Community 74 - "root"
Cohesion: 0.12
Nodes (17): require, inertiajs/inertia-laravel, laravel/ai, laravel/chisel, laravel/fortify, laravel/framework, laravel/horizon, laravel/pennant (+9 more)

### Community 75 - "Module: Auth"
Cohesion: 0.16
Nodes (4): CreateApiTokenRequest, CreateTeamRequest, IssueApiTokenRequest, UpdateLocaleRequest

### Community 76 - "Module: LeadSourcing"
Cohesion: 0.12
Nodes (7): {closure#1}(), {closure#1}(), LeadStatus, Discovered, Enriched, Scored, LeadFactory

### Community 77 - "Module: Onboarding"
Cohesion: 0.22
Nodes (8): {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), BusinessProfile, OnboardingProgress, BusinessProfilePolicy, OnboardingState

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

### Community 82 - "Module: LeadSourcing"
Cohesion: 0.14
Nodes (9): {closure#1}(), DiscoveryRunStatus, Completed, Failed, Pending, Running, {closure#2}(), {closure#4}() (+1 more)

### Community 83 - "app"
Cohesion: 0.13
Nodes (9): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#8}() (+1 more)

### Community 84 - "Database: migrations"
Cohesion: 0.21
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 85 - "Frontend: components/ui"
Cohesion: 0.12
Nodes (11): delegatedProps, emits, forwarded, props, delegatedProps, emits, forwarded, props (+3 more)

### Community 86 - "Database: factories"
Cohesion: 0.16
Nodes (4): AiRequest, AiRequestFactory, {closure#6}(), {closure#7}()

### Community 88 - "Module: Core"
Cohesion: 0.15
Nodes (7): {closure#1}(), Currency, Aed, Eur, Gbp, Usd, {closure#5}()

### Community 89 - "Database: factories"
Cohesion: 0.20
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
Cohesion: 0.18
Nodes (10): {closure#10}(), {closure#11}(), {closure#12}(), {closure#3}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+2 more)

### Community 94 - "Tests: LeadSourcing"
Cohesion: 0.22
Nodes (7): {closure#10}(), {closure#2}(), {closure#3}(), {closure#7}(), {closure#9}(), fakePlaces(), placesQuery()

### Community 95 - "Tests: LeadSourcing"
Cohesion: 0.22
Nodes (13): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#2}(), {closure#4}() (+5 more)

### Community 97 - "Module: Auth"
Cohesion: 0.23
Nodes (4): {closure#2}(), {closure#2}(), TeamInvitationMail, InvitationUrl

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
Cohesion: 0.23
Nodes (3): PasswordValidationRules, PasswordUpdateRequest, ProfileDeleteRequest

### Community 103 - "Module: BusinessIntelligence"
Cohesion: 0.24
Nodes (4): BackgroundCheckFindings, {closure#1}(), {closure#2}(), {closure#3}()

### Community 105 - "Module: Enrichment"
Cohesion: 0.26
Nodes (4): ExtractedFacts, {closure#1}(), {closure#2}(), {closure#3}()

### Community 107 - "Module: LeadSourcing"
Cohesion: 0.20
Nodes (3): FakeLeadSourceProvider, {closure#6}(), {closure#9}()

### Community 109 - "Module: Onboarding"
Cohesion: 0.18
Nodes (8): {closure#1}(), CompanySize, Enterprise, Large, Medium, Micro, Small, {closure#2}()

### Community 110 - "root"
Cohesion: 0.17
Nodes (11): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+3 more)

### Community 111 - "Infra: .github"
Cohesion: 0.17
Nodes (11): default-days, patterns, github-actions, interval, updates, cooldown, directory, groups (+3 more)

### Community 117 - "Database: seeders"
Cohesion: 0.25
Nodes (3): AiPromptTemplateSeeder, DatabaseSeeder, TeamRolesSeeder

### Community 119 - "root"
Cohesion: 0.18
Nodes (10): @inertiajs/vite@^3.0.0, laravel-vite-plugin@^3.0.0, @tailwindcss/vite@^4.1.11, vite-plus@1.4.0, @vitejs/plugin-vue@^6.0.0, @inertiajs/vite, laravel-vite-plugin, @tailwindcss/vite (+2 more)

### Community 120 - "Tests: Auth"
Cohesion: 0.29
Nodes (10): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}() (+2 more)

### Community 122 - "root"
Cohesion: 0.20
Nodes (10): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+2 more)

### Community 124 - "resources"
Cohesion: 0.20
Nodes (9): pulse.cache, pulse.exceptions, pulse.queues, pulse.servers, pulse.slow-jobs, pulse.slow-outgoing-requests, pulse.slow-queries, pulse.slow-requests (+1 more)

### Community 132 - "Frontend: components/ui"
Cohesion: 0.25
Nodes (4): props, delegatedProps, props, props

### Community 133 - "Frontend: components/ui"
Cohesion: 0.25
Nodes (4): props, width, props, SkeletonProps

### Community 134 - "Tests: Auth"
Cohesion: 0.25
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}()

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

### Community 143 - "Tests: Auth"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#5}(), {closure#6}()

### Community 145 - "Database: migrations"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 148 - "root"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 149 - "root"
Cohesion: 0.40
Nodes (5): extra, laravel, post-create-project, dont-discover, installer

### Community 175 - "Docs: ai-prompts.md"
Cohesion: 0.50
Nodes (4): Prompt injection safety (data, not instructions), AI safety (content is data, never instructions), Scraping / acquisition ethics (ToS, robots.txt), robots.txt (allow all)

## Knowledge Gaps
- **100 isolated node(s):** `Success`, `Failed`, `Reasoning`, `Cheap`, `Pending` (+95 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1356 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **83 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Team` connect `Teams & Settings` to `Auth & Teams (domain)`, `ICP Profiles`, `Auth: API Tokens & Teams`, `Business Intelligence`, `Tests: Auth`, `Auth: Profile & Security`, `Auth: Invitations & Tokens`, `ICP Tests`, `AI Platform (AiClient)`, `Core: i18n & Localisation`, `BI & Enrichment Jobs`, `Onboarding Steps`, `Module: AiPlatform`, `Tests: LeadSourcing`, `Tests: Core`, `Module: Core`, `Module: Core`, `Tests: AiPlatform`, `Module: Onboarding`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `Database: factories`, `Module: Enrichment`, `Module: LeadSourcing`, `Tests: Onboarding`, `Module: ModuleServiceProvider.php`, `Tests: Enrichment`, `Module: Onboarding`, `Tests: Core`, `Tests: LeadSourcing`, `Tests: Enrichment`, `Module: LeadSourcing`, `Module: Onboarding`, `Tests: BusinessIntelligence`, `Database: factories`, `Tests: LeadSourcing`, `Tests: LeadSourcing`, `Database: factories`, `Database: factories`?**
  _High betweenness centrality (0.090) - this node is a cross-community bridge._
- **What connects `Success`, `Failed`, `Reasoning` to the rest of the system?**
  _100 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `UI Component Library` be split into smaller, more focused modules?**
  _Cohesion score 0.039414414414414414 - nodes in this community are weakly interconnected._
- **Why does `User` connect `Auth & Teams (domain)` to `Tests: Auth`, `ICP Profiles`, `Auth: API Tokens & Teams`, `Business Intelligence`, `Tests: Auth`, `app`, `Auth: Profile & Security`, `Teams & Settings`, `Tests: Auth`, `Auth: Invitations & Tokens`, `ICP Tests`, `Tests: Auth`, `app`, `Core: i18n & Localisation`, `Tests: LeadSourcing`, `Module: Core`, `Module: Core`, `Tests: Settings`, `Tests: BusinessIntelligence`, `Tests: Onboarding`, `Module: Onboarding`, `Tests: Core`, `Module: Onboarding`, `Tests: Auth`, `Tests: LeadSourcing`, `Tests: LeadSourcing`, `app`, `Tests: Auth`, `Tests: Auth`, `Tests: Auth`, `Database: factories`?**
  _High betweenness centrality (0.064) - this node is a cross-community bridge._
- **Should `Auth & Teams (domain)` be split into smaller, more focused modules?**
  _Cohesion score 0.03939393939393939 - nodes in this community are weakly interconnected._
- **Why does `TeamContext` connect `Business Intelligence` to `Auth & Teams (domain)`, `ICP Profiles`, `Auth: API Tokens & Teams`, `Auth: Profile & Security`, `Teams & Settings`, `Auth: Invitations & Tokens`, `ICP Tests`, `AI Platform (AiClient)`, `BI & Enrichment Jobs`, `Module: AiPlatform`, `Tests: LeadSourcing`, `Tests: Core`, `Module: Core`, `Tests: AiPlatform`, `Tests: Enrichment`, `Tests: BusinessIntelligence`, `Module: Enrichment`, `Tests: Onboarding`, `Module: ModuleServiceProvider.php`, `Tests: Enrichment`, `Module: Onboarding`, `Tests: LeadSourcing`, `Tests: Enrichment`, `Module: LeadSourcing`, `Module: Onboarding`, `Tests: BusinessIntelligence`, `Tests: LeadSourcing`, `Tests: LeadSourcing`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **Should `Dashboard & App Shell` be split into smaller, more focused modules?**
  _Cohesion score 0.04487004103967168 - nodes in this community are weakly interconnected._