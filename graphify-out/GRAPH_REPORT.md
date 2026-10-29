# Graph Report - AlimaProspects  (2026-10-29)

## Corpus Check
- Large corpus: 857 files · ~242,584 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 3627 nodes · 9119 edges · 149 communities (119 shown, 30 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 360 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- UI Component Library
- Auth & Tenancy + ICP Profiles
- Misc
- Lead Sourcing Pages + Frontend Types
- Misc (2)
- Leads Workspace + App (misc)
- Leads Workspace
- Leads Workspace + Core Tenancy & Base
- Leads Workspace (2)
- Misc (3)
- Leads UI Components
- UI Component Library (2)
- Misc (4)
- Onboarding Pages + ICP Pages
- Auth & Tenancy
- Leads Workspace (3)
- Leads UI Components + Leads Pages
- Lead Sourcing
- ICP Profiles
- Settings Pages
- Leads Workspace (4)
- UI Component Library (3)
- Auth & Tenancy (2)
- Enrichment
- Leads Workspace (5)
- Auth & Tenancy (3)
- Auth & Tenancy (4)
- Lead Sourcing + Enrichment
- Leads Workspace (6)
- Core Tenancy & Base
- Business Intelligence
- Auth & Tenancy (5)
- Lead Sourcing (2)
- AI Platform
- UI Component Library (4)
- Leads Pages
- Leads Workspace (7)
- UI Component Library (5)
- Lead Scoring
- Leads Workspace (8)
- Misc + Lead Scoring
- Business Intelligence + Lead Scoring
- Lead Sourcing + Core Tenancy & Base
- AI Platform (2)
- AI Platform (3)
- Lead Scoring (2)
- UI Component Library (6)
- Enrichment (2)
- ICP Profiles (2)
- Lead Sourcing (3)
- Leads Workspace (9)
- Leads Workspace (10)
- Onboarding
- UI Component Library (7)
- Lead Scoring (3)
- UI Component Library (8)
- Leads UI Components (2)
- Business Intelligence (2)
- ICP Profiles (3)
- Leads Workspace (11)
- Onboarding (2)
- AI Platform (4)
- Onboarding + Core Tenancy & Base
- Lead Scoring (4)
- Moduleserviceprovider.Php + Core Tenancy & Base
- Enrichment (3)
- Lead Sourcing + Leads Workspace
- Leads Workspace (12)
- UI Component Library (9)
- Pages Business-Intel
- Lead Sourcing (4)
- Lead Sourcing (5)
- Leads Workspace (13)
- App (misc)
- App (misc) (2)
- Auth & Tenancy (6)
- Lead Sourcing (6)
- Lead Sourcing (7)
- Leads Workspace (14)
- Lead Scoring (5)
- UI Component Library (10)
- Leads Pages (2)
- Enrichment (4)
- Lead Scoring (6)
- Core Tenancy & Base (2)
- Leads UI Components (3)
- Pages Dashboard.Vue
- Leads Workspace (15)
- Lead Scoring (7)
- UI Component Library (11)
- Frontend Types
- Core Tenancy & Base + Business Intelligence
- App Screenshots
- AI Platform (5)
- AI Platform (6)
- Auth & Tenancy (7)
- Core Tenancy & Base (3)
- Enrichment (5)
- Lead Scoring (8)
- Lead Sourcing (8)
- Lead Scoring (9)
- Resources
- Lead Sourcing (9)
- Leads Workspace (16)
- Onboarding (3)
- Onboarding (4)
- Resources + Vue Composables
- Vue Composables
- Business Intelligence (3)
- Enrichment (6)
- App Screenshots (2)
- App Screenshots (3)
- App (misc) (3)
- App (misc) (4)
- Lead Sourcing (10)
- Leads Workspace (17)
- Resources (2)
- Lead Sourcing (11)
- App (misc) (5)
- Onboarding (5)
- UI Component Library (12)
- AI Platform (7)
- Core Tenancy & Base (4)
- Leads Workspace (18)
- Lead Scoring (10)
- App (misc) (6)
- UI Component Library (13)
- UI Component Library (14)
- App Screenshots (4)
- App Screenshots (5)
- AI Platform (8)
- Enrichment (7)
- Enrichment (8)
- Leads Workspace (19)
- Onboarding (6)
- Frontend Types (2)
- Onboarding UI
- App Screenshots (6)
- Enrichment (9)
- Leads Workspace (20)
- Leads Workspace (21)
- UI Component Library (15)
- App Screenshots (7)
- App Screenshots (8)
- Components Appearancetabs.Vue
- Onboarding (7)

## God Nodes (most connected - your core abstractions)
1. `Team` - 209 edges
2. `User` - 207 edges
3. `TeamContext` - 161 edges
4. `Lead` - 123 edges
5. `AuditLogger` - 115 edges
6. `cn()` - 103 edges
7. `Icp` - 68 edges
8. `Controller` - 64 edges
9. `LeadSource` - 51 edges
10. `BelongsToTeam` - 49 edges

## Surprising Connections (you probably didn't know these)
- `{closure#2}()` --references--> `Team`  [EXTRACTED]
  app/Http/Middleware/HandleInertiaRequests.php → app/Modules/Core/Models/Team.php
- `{closure#1}()` --references--> `User`  [EXTRACTED]
  app/Modules/Auth/Http/Controllers/Web/TeamSettingsController.php → app/Models/User.php
- `{closure#2}()` --references--> `User`  [EXTRACTED]
  app/Modules/LeadsWorkspace/Http/Controllers/Web/LeadController.php → app/Models/User.php
- `{closure#1}()` --references--> `ClaudeAiClient`  [EXTRACTED]
  app/Modules/AiPlatform/Providers/AiPlatformServiceProvider.php → app/Modules/AiPlatform/Services/ClaudeAiClient.php
- `{closure#2}()` --calls--> `TeamInvitationMail`  [EXTRACTED]
  app/Modules/Auth/Actions/ResendInvitationAction.php → app/Modules/Auth/Mail/TeamInvitationMail.php

## Import Cycles
- 3-file cycle: `resources/js/components/ui/sidebar/SidebarMenuButton.vue -> resources/js/components/ui/sidebar/SidebarMenuButtonChild.vue -> resources/js/components/ui/sidebar/index.ts -> resources/js/components/ui/sidebar/SidebarMenuButton.vue`

## Hyperedges (group relationships)
- **Alima brand system applied to About page (cream-led, orange decorative, panda mascot)** — resources_screenshots_about_cloudcreambg, resources_screenshots_about_signalorangeaccent, resources_screenshots_about_alimalogo, resources_screenshots_about_pandaillustration, docs_brand_brand_tokens [INFERRED 0.85]
- **** — resources_screenshots_about1_navbar, resources_screenshots_about1_logo, resources_screenshots_about1_letstalk_cta [INFERRED 0.85]
- **** — resources_screenshots_clients_hero, resources_screenshots_clients_table, resources_screenshots_clients_brand [INFERRED 0.75]
- **Dashboard Home layout composition** — resources_screenshots_dashboard1_sidebar, resources_screenshots_dashboard1_topbar, resources_screenshots_dashboard1_hero, resources_screenshots_dashboard1_statrow [INFERRED 0.85]
- **** — resources_screenshots_landing_page_herosection, resources_screenshots_landing_page_pandamascot, resources_screenshots_landing_page_ctabuttons [INFERRED 0.85]

## Communities (149 total, 30 thin omitted)

### Community 0 - "UI Component Library"
Cohesion: 0.04
Nodes (51): delegatedProps, props, props, props, props, props, props, props (+43 more)

### Community 1 - "Auth & Tenancy + ICP Profiles"
Cohesion: 0.05
Nodes (39): AcceptInvitationAction, {closure#2}(), {closure#1}(), CreateTeamAction, {closure#1}(), {closure#2}(), RemoveTeamMemberAction, {closure#1}() (+31 more)

### Community 2 - "Misc"
Cohesion: 0.04
Nodes (41): ActiveIcon, { appearance, updateAppearance }, options, { t }, initials, isActive(), { isCurrentUrl, isCurrentOrParentUrl }, Item (+33 more)

### Community 3 - "Lead Sourcing Pages + Frontend Types"
Cohesion: 0.04
Nodes (55): ADR-0008, props, { t }, variant, BadgeVariants, confirmingDelete, props, { t } (+47 more)

### Community 4 - "Misc (2)"
Cohesion: 0.07
Nodes (22): passwordInput, Props, emit, name, { register, isLoading, error, isSupported }, showForm, Props, { verify, isLoading, error, isSupported } (+14 more)

### Community 5 - "Leads Workspace + App (misc)"
Cohesion: 0.05
Nodes (24): UserController, UserResource, BusinessIntelReportResource, LeadContactResource, IcpCriterionResource, LeadActivityResource, LeadNoteResource, LeadTagResource (+16 more)

### Community 6 - "Leads Workspace"
Cohesion: 0.05
Nodes (26): {closure#1}(), CompleteLeadTaskAction, CreateLeadNoteAction, {closure#1}(), CreateLeadTaskAction, DeleteLeadTaskAction, {closure#2}(), {closure#3}() (+18 more)

### Community 7 - "Leads Workspace + Core Tenancy & Base"
Cohesion: 0.07
Nodes (8): AiRequest, BelongsToTeam, {closure#1}(), {closure#2}(), AuditLog, {closure#4}(), LeadAssignment, LeadTag

### Community 8 - "Leads Workspace (2)"
Cohesion: 0.05
Nodes (18): {closure#1}(), {closure#1}(), {closure#5}(), {closure#1}(), {closure#2}(), EnsureDefaultPipelineStagesAction, FindLeadAction, {closure#1}() (+10 more)

### Community 9 - "Misc (3)"
Cohesion: 0.05
Nodes (25): className, Props, auth, { isCurrentUrl, whenCurrentUrl }, mainNavItems, page, Props, rightNavItems (+17 more)

### Community 10 - "Leads UI Components"
Cohesion: 0.05
Nodes (41): { dateTime }, form, props, { t }, { dateTime, date }, done, form, isAdding (+33 more)

### Community 11 - "UI Component Library (2)"
Cohesion: 0.04
Nodes (40): emits, forwarded, props, props, props, emits, forwarded, props (+32 more)

### Community 12 - "Misc (4)"
Cohesion: 0.05
Nodes (25): Props, emit, handleDelete(), isDeleting, props, code, { copy, copied }, isOpen (+17 more)

### Community 13 - "Onboarding Pages + ICP Pages"
Cohesion: 0.05
Nodes (39): emit, isCompleted(), isReachable(), percent, props, select(), { t }, total (+31 more)

### Community 14 - "Auth & Tenancy"
Cohesion: 0.07
Nodes (16): Controller, IssueApiTokenAction, SwitchTeamAction, {closure#1}(), UpdateTeamAction, CreateTeamData, TeamController, TeamSwitchController (+8 more)

### Community 15 - "Leads Workspace (3)"
Cohesion: 0.07
Nodes (11): {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), ImportLeadsAction, LeadImportResult, LeadsWorkspaceException, LeadTransferController (+3 more)

### Community 16 - "Leads UI Components + Leads Pages"
Cohesion: 0.05
Nodes (40): ADR-0007, emit, isFiltering, props, search, { t }, toggle(), toggleId() (+32 more)

### Community 17 - "Lead Sourcing"
Cohesion: 0.08
Nodes (12): {closure#1}(), FindDiscoveryRunAction, {closure#1}(), GetDiscoveryRunsAction, StartDiscoveryRunAction, StartDiscoveryData, DiscoveryController, DiscoveryController (+4 more)

### Community 18 - "ICP Profiles"
Cohesion: 0.11
Nodes (10): DeleteIcpAction, FindIcpAction, GetIcpsAction, UpdateIcpAction, IcpData, IcpController, {closure#1}(), IcpController (+2 more)

### Community 19 - "Settings Pages"
Cohesion: 0.05
Nodes (22): props, { hasSetupData, clearTwoFactorAuthData }, Props, showSetupModal, isRecoveryCodesVisible, recoveryCodeSectionRef, { recoveryCodesList, fetchRecoveryCodes, errors }, errors (+14 more)

### Community 20 - "Leads Workspace (4)"
Cohesion: 0.08
Nodes (13): {closure#1}(), {closure#3}(), LeadEnriched, DiscoveryRunCompleted, RecordLeadActivityAction, {closure#2}(), RecordDiscoveryActivity, {closure#1}() (+5 more)

### Community 21 - "UI Component Library (3)"
Cohesion: 0.05
Nodes (32): emits, modelValue, props, delegatedProps, props, navigationMenuTriggerStyle, delegatedProps, emits (+24 more)

### Community 22 - "Auth & Tenancy (2)"
Cohesion: 0.08
Nodes (7): TeamMembershipException, TeamSettingsController, TeamPolicy, HasTeams, Team, WritesIcpCriteria, {closure#1}()

### Community 23 - "Enrichment"
Cohesion: 0.08
Nodes (9): AiClient, UntrustedContent, WebContentFetcher, FakeWebContentFetcher, {closure#4}(), EnrichLeadAction, ExtractedFacts, {closure#1}() (+1 more)

### Community 24 - "Leads Workspace (5)"
Cohesion: 0.09
Nodes (9): {closure#1}(), User, {closure#1}(), DeleteSavedViewAction, SaveViewAction, {closure#2}(), {closure#3}(), SavedView (+1 more)

### Community 25 - "Auth & Tenancy (3)"
Cohesion: 0.10
Nodes (10): {closure#1}(), InviteTeamMemberAction, {closure#1}(), {closure#2}(), ResendInvitationAction, RevokeInvitationAction, InvitationException, TeamInvitationController (+2 more)

### Community 26 - "Auth & Tenancy (4)"
Cohesion: 0.07
Nodes (8): CreateApiTokenRequest, InviteMemberRequest, IssueApiTokenRequest, UpdateMemberRoleRequest, UpdateTeamRequest, AcceptBackgroundCheckRequest, MoveLeadStageRequest, SaveLeadNoteRequest

### Community 27 - "Lead Sourcing + Enrichment"
Cohesion: 0.11
Nodes (6): {closure#2}(), LeadDeduplicator, Lead, LeadPolicy, {closure#1}(), {closure#1}()

### Community 28 - "Leads Workspace (6)"
Cohesion: 0.08
Nodes (15): DeletePipelineStageAction, {closure#1}(), ReorderPipelineStagesAction, {closure#1}(), SavePipelineStageAction, {closure#1}(), {closure#1}(), {closure#2}() (+7 more)

### Community 29 - "Core Tenancy & Base"
Cohesion: 0.08
Nodes (12): {closure#1}(), {closure#2}(), HandleInertiaRequests, {closure#1}(), SupportedLocale, Arabic, English, TextDirection (+4 more)

### Community 30 - "Business Intelligence"
Cohesion: 0.07
Nodes (17): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}(), BackgroundCheckStatus, Accepted, Failed (+9 more)

### Community 31 - "Auth & Tenancy (5)"
Cohesion: 0.07
Nodes (13): {closure#1}(), {closure#1}(), {closure#1}(), InviteMemberData, {closure#1}(), {closure#3}(), {closure#3}(), {closure#1}() (+5 more)

### Community 32 - "Lead Sourcing (2)"
Cohesion: 0.09
Nodes (14): {closure#1}(), DiscoveryRunStatus, Completed, Failed, Pending, Running, DiscoveryRunProgressed, {closure#1}() (+6 more)

### Community 33 - "AI Platform"
Cohesion: 0.10
Nodes (7): AiTransport, AiPromptRequest, AiTransportResult, AiTransportException, CostCalculator, FakeAiTransport, LaravelAiTransport

### Community 34 - "UI Component Library (4)"
Cohesion: 0.06
Nodes (23): emits, forwarded, props, props, delegatedProps, emits, forwarded, props (+15 more)

### Community 35 - "Leads Pages"
Cohesion: 0.07
Nodes (22): addField, addSlot, announcement, cardsFor(), confirmingRemovalId, defaultStageName, draggingLeadId, dropTargetStageId (+14 more)

### Community 36 - "Leads Workspace (7)"
Cohesion: 0.14
Nodes (3): LeadCollaborationController, PipelineController, SavedViewController

### Community 37 - "UI Component Library (5)"
Cohesion: 0.06
Nodes (19): emits, forwarded, props, delegatedProps, emits, forwarded, props, props (+11 more)

### Community 38 - "Lead Scoring"
Cohesion: 0.11
Nodes (10): {closure#2}(), IcpCriterion, {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+2 more)

### Community 39 - "Leads Workspace (8)"
Cohesion: 0.08
Nodes (14): {closure#1}(), LeadActivityType, Assigned, Discovered, Enriched, Imported, NoteAdded, Scored (+6 more)

### Community 40 - "Misc + Lead Scoring"
Cohesion: 0.16
Nodes (6): {closure#5}(), RunBackgroundCheckJob, EnrichLeadJob, {closure#2}(), ScoreLeadJob, {closure#2}()

### Community 41 - "Business Intelligence + Lead Scoring"
Cohesion: 0.10
Nodes (4): AcceptBackgroundCheckData, BackgroundCheckFindings, DeterministicScore, QualitativeFit

### Community 42 - "Lead Sourcing + Core Tenancy & Base"
Cohesion: 0.14
Nodes (4): HttpWebContentFetcher, AbstractHttpLeadSourceProvider, {closure#1}(), CircuitBreaker

### Community 43 - "AI Platform (2)"
Cohesion: 0.13
Nodes (6): CreditSource, CreditAllowance, {closure#1}(), AiCreditGuard, AiUsageLogger, StubCreditSource

### Community 44 - "AI Platform (3)"
Cohesion: 0.18
Nodes (5): AiRequestStatus, Failed, Success, AiPromptTemplate, ClaudeAiClient

### Community 45 - "Lead Scoring (2)"
Cohesion: 0.09
Nodes (7): {closure#1}(), {closure#1}(), {closure#1}(), {closure#4}(), LeadScore, ScoreFeedback, LeadScorePolicy

### Community 46 - "UI Component Library (6)"
Cohesion: 0.09
Nodes (15): SidebarMenuButtonVariants, delegatedProps, { isMobile, state }, props, props, SidebarMenuButtonProps, emits, forwarded (+7 more)

### Community 47 - "Enrichment (2)"
Cohesion: 0.10
Nodes (7): LeadContact, {closure#1}(), {closure#3}(), {closure#5}(), {closure#1}(), {closure#2}(), {closure#6}()

### Community 48 - "ICP Profiles (2)"
Cohesion: 0.12
Nodes (9): {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), Icp, IcpPolicy (+1 more)

### Community 49 - "Lead Sourcing (3)"
Cohesion: 0.12
Nodes (3): LeadSourceProvider, DiscoveryQuery, FakeLeadSourceProvider

### Community 50 - "Leads Workspace (9)"
Cohesion: 0.16
Nodes (19): {closure#1}(), {closure#2}(), {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}() (+11 more)

### Community 51 - "Leads Workspace (10)"
Cohesion: 0.11
Nodes (9): BulkLeadRequestData, BulkLeadAction, AddTags, Assign, MoveStage, RemoveTags, Unassign, {closure#1}() (+1 more)

### Community 52 - "Onboarding"
Cohesion: 0.12
Nodes (10): OnboardingStep, Company, Industry, Locations, Offering, Pricing, Review, {closure#1}() (+2 more)

### Community 53 - "UI Component Library (7)"
Cohesion: 0.09
Nodes (15): emits, forwarded, props, props, delegatedProps, props, props, props (+7 more)

### Community 54 - "Lead Scoring (3)"
Cohesion: 0.12
Nodes (9): {closure#1}(), CriterionScoreList, CriterionScore, {closure#1}(), CriterionMatch, Matched, NotMatched, Unknown (+1 more)

### Community 55 - "UI Component Library (8)"
Cohesion: 0.09
Nodes (16): delegatedProps, emits, forwarded, props, delegatedProps, emits, forwarded, props (+8 more)

### Community 56 - "Leads UI Components (2)"
Cohesion: 0.11
Nodes (20): account, workspace, actor(), { dateTime }, describe(), icons, members(), props (+12 more)

### Community 57 - "Business Intelligence (2)"
Cohesion: 0.16
Nodes (5): FindReportAction, GetLatestReportAction, RerunBackgroundCheckAction, BusinessIntelController, BusinessIntelController

### Community 59 - "ICP Profiles (3)"
Cohesion: 0.12
Nodes (13): CriterionData, {closure#1}(), {closure#2}(), CriterionType, BuyingSignal, CompanySize, Exclusion, Geography (+5 more)

### Community 60 - "Leads Workspace (11)"
Cohesion: 0.11
Nodes (10): {closure#1}(), LeadSort, Company, Fit, Freshness, Newest, {closure#1}(), LeadSortDirection (+2 more)

### Community 61 - "Onboarding (2)"
Cohesion: 0.12
Nodes (8): {closure#1}(), {closure#2}(), CompleteOnboardingAction, {closure#1}(), {closure#2}(), OnboardingException, BusinessProfile, BusinessProfilePolicy

### Community 63 - "Onboarding + Core Tenancy & Base"
Cohesion: 0.12
Nodes (11): {closure#1}(), Market, Uae, Uk, {closure#1}(), ServedRegion, Other, Uae (+3 more)

### Community 64 - "Lead Scoring (4)"
Cohesion: 0.14
Nodes (5): AiCreditExceededException, InvalidAiResponseException, {closure#2}(), ScoreLeadAction, {closure#1}()

### Community 65 - "Moduleserviceprovider.Php + Core Tenancy & Base"
Cohesion: 0.15
Nodes (8): AiPlatformServiceProvider, AuthServiceProvider, BusinessIntelligenceServiceProvider, CoreServiceProvider, IcpServiceProvider, LeadsWorkspaceServiceProvider, ModuleServiceProvider, ScoringServiceProvider

### Community 67 - "Lead Sourcing + Leads Workspace"
Cohesion: 0.13
Nodes (3): LeadResource, LeadController, LeadDetailResource

### Community 68 - "Leads Workspace (12)"
Cohesion: 0.17
Nodes (3): ExportLeadsAction, {closure#3}(), LeadCsvExporter

### Community 69 - "UI Component Library (9)"
Cohesion: 0.11
Nodes (14): delegatedProps, emits, forwarded, props, delegatedProps, forwarded, props, forwarded (+6 more)

### Community 70 - "Pages Business-Intel"
Cohesion: 0.11
Nodes (14): acceptForm, confidencePct, findings, isRunning, page, progress, props, { t } (+6 more)

### Community 71 - "Lead Sourcing (4)"
Cohesion: 0.18
Nodes (5): LeadQuotaSource, LeadQuota, {closure#3}(), DiscoveryLimitGuard, StubLeadQuotaSource

### Community 72 - "Lead Sourcing (5)"
Cohesion: 0.14
Nodes (10): {closure#1}(), {closure#1}(), {closure#2}(), {closure#3}(), LeadSource, CompaniesHouse, GooglePlaces, Import (+2 more)

### Community 73 - "Leads Workspace (13)"
Cohesion: 0.17
Nodes (4): {closure#1}(), LeadFilters, {closure#1}(), SaveViewRequest

### Community 74 - "App (misc)"
Cohesion: 0.16
Nodes (5): CreateNewUser, ResetUserPassword, PasswordValidationRules, PasswordUpdateRequest, CreatePersonalTeamAction

### Community 75 - "App (misc) (2)"
Cohesion: 0.16
Nodes (4): ProfileValidationRules, ProfileController, ProfileDeleteRequest, ProfileUpdateRequest

### Community 76 - "Auth & Tenancy (6)"
Cohesion: 0.14
Nodes (4): RevokeApiTokenAction, ApiTokenController, {closure#1}(), ApiTokenResource

### Community 79 - "Leads Workspace (14)"
Cohesion: 0.12
Nodes (6): {closure#1}(), StageOutcome, Lost, Won, SavePipelineStageRequest, PipelineStagePolicy

### Community 80 - "Lead Scoring (5)"
Cohesion: 0.14
Nodes (8): {closure#1}(), LeadFilterOptions, {closure#1}(), ScoreGrade, A, B, C, D

### Community 81 - "UI Component Library (10)"
Cohesion: 0.16
Nodes (15): emits, isMobile, open, openMobile, props, setOpen(), setOpenMobile(), state (+7 more)

### Community 82 - "Leads Pages (2)"
Cohesion: 0.13
Nodes (15): autoMap(), canSubmit, fieldLabels, fileName, form, hasFile, headers, { number } (+7 more)

### Community 83 - "Enrichment (4)"
Cohesion: 0.14
Nodes (8): EmailVerificationResult, {closure#1}(), EmailVerificationStatus, DisposableDomain, InvalidSyntax, MxFound, NoMxRecord, Unknown

### Community 85 - "Core Tenancy & Base (2)"
Cohesion: 0.21
Nodes (3): HandleAppearance, LogContext, SetLocale

### Community 86 - "Leads UI Components (3)"
Cohesion: 0.13
Nodes (15): action, actions, apply(), canApply, emit, needs, { number }, overLimit (+7 more)

### Community 87 - "Pages Dashboard.Vue"
Cohesion: 0.13
Nodes (11): flow, hasStarted, longDate, onboarding, page, showSetup, stats, { t } (+3 more)

### Community 88 - "Leads Workspace (15)"
Cohesion: 0.21
Nodes (4): {closure#1}(), ListLeadsAction, {closure#2}(), LeadQueryBuilder

### Community 89 - "Lead Scoring (7)"
Cohesion: 0.15
Nodes (5): {closure#1}(), FeedbackRating, Bad, Good, RecordScoreFeedbackRequest

### Community 90 - "UI Component Library (11)"
Cohesion: 0.14
Nodes (6): Props, uniqueErrors, props, props, props, AlertVariants

### Community 91 - "Frontend Types"
Cohesion: 0.17
Nodes (13): Auth, ComponentCustomProperties, GlobalDirectives, ImportMeta, ImportMetaEnv, InertiaConfig, @inertiajs/core, vite/client (+5 more)

### Community 92 - "Core Tenancy & Base + Business Intelligence"
Cohesion: 0.21
Nodes (3): AiPlatformException, BackgroundCheckException, DomainException

### Community 93 - "App Screenshots"
Cohesion: 0.18
Nodes (14): Alima Brand Tokens, About Page (alima.test/about), Alima Logo (panda mascot), Cloud Cream Background, Feature Cards Row (Clients/Calendar/Approvals/Portal), Features Section - One workspace, every part of the job, Eyebrow Label - ABOUT THE PLANNER, Hero Section - Social content, run end to end (+6 more)

### Community 94 - "AI Platform (5)"
Cohesion: 0.18
Nodes (5): StructuredAiResponse, AiTier, Cheap, Reasoning, {closure#1}()

### Community 96 - "Auth & Tenancy (7)"
Cohesion: 0.23
Nodes (3): {closure#2}(), TeamInvitationMail, InvitationUrl

### Community 97 - "Core Tenancy & Base (3)"
Cohesion: 0.17
Nodes (7): {closure#1}(), Currency, Aed, Eur, Gbp, Usd, {closure#5}()

### Community 98 - "Enrichment (5)"
Cohesion: 0.17
Nodes (6): EnrichmentStatus, Enriched, Enriching, Failed, Pending, {closure#2}()

### Community 99 - "Lead Scoring (8)"
Cohesion: 0.32
Nodes (3): FindActiveIcpAction, ScoreController, {closure#1}()

### Community 100 - "Lead Sourcing (8)"
Cohesion: 0.23
Nodes (6): LeadInterestGate, {closure#1}(), {closure#2}(), LeadSourcingServiceProvider, AllowAllLeadInterestGate, LeadSourceProviderRegistry

### Community 102 - "Resources"
Cohesion: 0.15
Nodes (12): brand, BrandLocale, fonts, grid, motion, proportion, radius, semanticDark (+4 more)

### Community 103 - "Lead Sourcing (9)"
Cohesion: 0.17
Nodes (6): {closure#1}(), {closure#1}(), LeadStatus, Discovered, Enriched, Scored

### Community 106 - "Onboarding (4)"
Cohesion: 0.18
Nodes (8): {closure#1}(), CompanySize, Enterprise, Large, Medium, Micro, Small, {closure#2}()

### Community 107 - "Resources + Vue Composables"
Cohesion: 0.21
Nodes (6): applyFromPage(), initializeLocaleDirection(), initializeEcho(), Window, { title = '', description = '' }, palette

### Community 108 - "Vue Composables"
Cohesion: 0.30
Nodes (11): appearance, getStoredAppearance(), handleSystemThemeChange(), initializeTheme(), mediaQuery(), prefersDark(), setCookie(), updateTheme() (+3 more)

### Community 110 - "Enrichment (6)"
Cohesion: 0.22
Nodes (3): DnsResolver, EnrichmentServiceProvider, SystemDnsResolver

### Community 111 - "App Screenshots (2)"
Cohesion: 0.22
Nodes (11): Alima Brand Tokens (Cream/Orange accent), About The Planner Eyebrow Label, Feature Cards Row, One Workspace Features Section, Social Content Run End To End Headline, About Hero Section, Let's Talk CTA Button, Alima Panda Logo (+3 more)

### Community 112 - "App Screenshots (3)"
Cohesion: 0.20
Nodes (11): Alima Planner Login Screenshot, Remember Me, Log In, Sign Up Controls, Alima Lowercase Wordmark Logo, Lime Green Brand Hero Panel, Editorial Masthead (Edition 01 - 2026, V1.0), Email Address Input Field, Welcome Back Login Form, Marketing Brighter Together Headline (+3 more)

### Community 116 - "Leads Workspace (17)"
Cohesion: 0.24
Nodes (3): {closure#1}(), LeadNote, LeadNotePolicy

### Community 117 - "Resources (2)"
Cohesion: 0.20
Nodes (9): pulse.cache, pulse.exceptions, pulse.queues, pulse.servers, pulse.slow-jobs, pulse.slow-outgoing-requests, pulse.slow-queries, pulse.slow-requests (+1 more)

### Community 120 - "Onboarding (5)"
Cohesion: 0.33
Nodes (3): {closure#1}(), OnboardingProgress, OnboardingState

### Community 121 - "UI Component Library (12)"
Cohesion: 0.22
Nodes (7): delegatedProps, emits, forwarded, props, SheetContentProps, delegatedProps, props

### Community 124 - "Leads Workspace (18)"
Cohesion: 0.25
Nodes (4): {closure#1}(), LeadTaskStatus, Done, Open

### Community 127 - "UI Component Library (13)"
Cohesion: 0.25
Nodes (4): props, delegatedProps, props, props

### Community 128 - "UI Component Library (14)"
Cohesion: 0.25
Nodes (4): props, width, props, SkeletonProps

### Community 129 - "App Screenshots (4)"
Cohesion: 0.32
Nodes (8): Alima Planner Dashboard (Home) Screenshot, Alima Brand Panda Mascot & Palette, Good Morning Hero Greeting Card, Needs Attention Counter (14 Decisions), Studio / Needs Attention / Mine Scope Tabs, Left Navigation Sidebar, Studio Metrics Stat Row, Top Bar (New, Search, Date, Notifications)

### Community 130 - "App Screenshots (5)"
Cohesion: 0.29
Nodes (8): Alima Planner Landing Page Screenshot, Brand Palette (Cloud Cream, Signal Orange accent, dark ink), CTA Buttons (Let's grow, See the work), Header Nav (logo, About, Log in, Let's talk), Hero Section (Every post, planned brighter), Purple Panda Mascot Illustration, Post Status Badges (Published/Scheduled/Waiting/Changes/Approved), This Week Content Calendar Preview

### Community 135 - "Onboarding (6)"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#1}()

### Community 136 - "Frontend Types (2)"
Cohesion: 0.33
Nodes (4): initializeFlashToast(), Appearance, FlashToast, ResolvedAppearance

### Community 137 - "Onboarding UI"
Cohesion: 0.38
Nodes (6): add(), draft, emit, props, remove(), { t }

### Community 138 - "App Screenshots (6)"
Cohesion: 0.38
Nodes (7): Alima Planner Dashboard (Home) Screenshot, Alima Cloud Cream / Lime Accent Brand Theme, Going Out Scheduled Posts Panel, In Review Unapproved Drafts Panel, Invoiced vs Paid Area Chart, Reliability / Publish Attempts Chart, Studio Navigation Sidebar

### Community 142 - "UI Component Library (15)"
Cohesion: 0.33
Nodes (3): delegatedProps, props, props

### Community 143 - "App Screenshots (7)"
Cohesion: 0.40
Nodes (6): Clients Screen (Alima Planner), Alima Brand Design Language, The Roster Hero Panel, Studio Navigation Sidebar, Clients Roster Table, Top Bar (New, Search, Date)

### Community 144 - "App Screenshots (8)"
Cohesion: 0.50
Nodes (5): About Page Screenshot (How It Works), Alima Brand Tokens (Cloud Cream, Lime, Mono labels), Stop Chasing Approvals CTA Card, Four-Step Numbered Process, How It Works Section

### Community 145 - "Components Appearancetabs.Vue"
Cohesion: 0.50
Nodes (3): { appearance, updateAppearance }, { t }, tabs

## Knowledge Gaps
- **137 isolated node(s):** `Success`, `Failed`, `Reasoning`, `Cheap`, `Pending` (+132 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 1211 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **30 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Team` connect `Auth & Tenancy (2)` to `Auth & Tenancy + ICP Profiles`, `Leads Workspace + App (misc)`, `Leads Workspace`, `Leads Workspace + Core Tenancy & Base`, `Leads Workspace (2)`, `Auth & Tenancy`, `Leads Workspace (3)`, `Lead Sourcing`, `ICP Profiles`, `Leads Workspace (5)`, `Auth & Tenancy (3)`, `Lead Sourcing + Enrichment`, `Leads Workspace (6)`, `Core Tenancy & Base`, `Business Intelligence`, `Auth & Tenancy (5)`, `AI Platform`, `Leads Workspace (7)`, `AI Platform (2)`, `AI Platform (3)`, `Enrichment (2)`, `ICP Profiles (2)`, `Leads Workspace (9)`, `Business Intelligence (2)`, `Onboarding (2)`, `Onboarding + Core Tenancy & Base`, `Moduleserviceprovider.Php + Core Tenancy & Base`, `Lead Sourcing + Leads Workspace`, `Leads Workspace (12)`, `Lead Sourcing (4)`, `Lead Sourcing (6)`, `Core Tenancy & Base (2)`, `Leads Workspace (15)`, `Lead Scoring (8)`, `Lead Sourcing (8)`, `Lead Scoring (9)`, `Onboarding (3)`, `Onboarding (5)`, `Lead Scoring (10)`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._
- **What connects `Success`, `Failed`, `Reasoning` to the rest of the system?**
  _137 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `UI Component Library` be split into smaller, more focused modules?**
  _Cohesion score 0.04126256392170693 - nodes in this community are weakly interconnected._
- **Why does `TeamContext` connect `Auth & Tenancy + ICP Profiles` to `Leads Workspace`, `Leads Workspace + Core Tenancy & Base`, `Leads Workspace (2)`, `Enrichment (9)`, `Auth & Tenancy`, `Leads Workspace (3)`, `Lead Sourcing`, `ICP Profiles`, `Leads Workspace (4)`, `Auth & Tenancy (2)`, `Enrichment`, `Leads Workspace (5)`, `Lead Sourcing + Enrichment`, `Leads Workspace (6)`, `Business Intelligence`, `Lead Sourcing (2)`, `AI Platform`, `Leads Workspace (7)`, `Misc + Lead Scoring`, `AI Platform (2)`, `AI Platform (3)`, `Enrichment (2)`, `ICP Profiles (2)`, `Business Intelligence (2)`, `Onboarding (2)`, `Lead Scoring (4)`, `Moduleserviceprovider.Php + Core Tenancy & Base`, `Leads Workspace (12)`, `Core Tenancy & Base (2)`, `Leads Workspace (15)`, `Lead Scoring (8)`, `Lead Sourcing (8)`, `Lead Scoring (9)`, `Onboarding (3)`, `Onboarding (5)`?**
  _High betweenness centrality (0.060) - this node is a cross-community bridge._
- **Should `Auth & Tenancy + ICP Profiles` be split into smaller, more focused modules?**
  _Cohesion score 0.05043859649122807 - nodes in this community are weakly interconnected._
- **Why does `User` connect `Leads Workspace (5)` to `Auth & Tenancy + ICP Profiles`, `Leads Workspace + App (misc)`, `Leads Workspace`, `Leads Workspace + Core Tenancy & Base`, `Leads Workspace (2)`, `Onboarding (6)`, `Leads Workspace (20)`, `Auth & Tenancy`, `Leads Workspace (3)`, `Lead Sourcing`, `ICP Profiles`, `Leads Workspace (4)`, `Auth & Tenancy (2)`, `Auth & Tenancy (3)`, `Lead Sourcing + Enrichment`, `Leads Workspace (6)`, `Core Tenancy & Base`, `Business Intelligence`, `Auth & Tenancy (5)`, `Leads Workspace (7)`, `Leads Workspace (8)`, `Lead Scoring (2)`, `ICP Profiles (2)`, `Business Intelligence (2)`, `Onboarding (2)`, `Onboarding + Core Tenancy & Base`, `Lead Sourcing + Leads Workspace`, `Leads Workspace (12)`, `App (misc)`, `App (misc) (2)`, `Auth & Tenancy (6)`, `Leads Workspace (14)`, `Core Tenancy & Base (2)`, `Lead Scoring (7)`, `Lead Scoring (8)`, `Lead Scoring (9)`, `Leads Workspace (17)`, `Lead Scoring (10)`?**
  _High betweenness centrality (0.049) - this node is a cross-community bridge._
- **Should `Misc` be split into smaller, more focused modules?**
  _Cohesion score 0.03944595001505571 - nodes in this community are weakly interconnected._