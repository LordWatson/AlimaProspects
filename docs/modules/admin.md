# Admin (internal operations panel)

> Status: **MVP — Phase 19a built; 19b and 19c to come** · Last updated: 2026-10-31 · See **ADR 0014** (supersedes ADR 0009)

## Purpose

The panel the AlimaProspects team uses to operate the platform. It reads and writes **every** resource in the product from **outside team scope**, lets an operator fix a customer's record, suspend a tenant, perform account operations and impersonate a user — with every cross-tenant read and write audited.

It explicitly does **not** own any business rule. Where a module owns an operation, the panel calls that module's Action; the generic writer is only for records nobody wrote an Action for. It is also **not** a place a tenant ever reaches: `/admin` answers 404 to everybody who is not an operator.

Three things about it are decisions rather than omissions, and all three are stated here so nobody has to infer them:

- **Web only.** There is no `/api/v1/admin`. A token that can read every tenant is a liability no MVP ops task needs, and it would have to be scoped, versioned and specified like everything else in ADR 0013. The module ships no `routes/api.php` at all, and `AdminRouteContractTest` asserts the API surface stays empty.
- **English only.** An internal tool carries no EN/AR or RTL obligation (ADR 0007 is about the product). The tenant app and the mobile app remain bilingual; admin screens use plain strings and no `t()`.
- **Not Filament.** ADR 0014 has the four reasons. The first is that Filament's generated screens write straight to models, which is the second unvalidated write path CLAUDE.md §2's rules exist to prevent.

## Code map

- **Backend:** `app/Modules/Admin/`
    - `Enums/` — `AdminRole` (`super_admin`, `support`, `finance`, `read_only`), `AdminAbility` (ten abilities), `ResourceGroup` (nav grouping), `FieldType`.
    - `Resources/` — `AdminResource` (the abstract descriptor), `ResourceRegistry`, and **40 descriptors**, one per model.
    - `Data/` — `ResourceColumn`, `ResourceField`, `ResourceFilter`, `ResourceQueryOptions`.
    - `Services/` — `ResourceQuery` (the cross-tenant index), `ResourceWriter` (the generic write path), `AdminAudit`, `Impersonator`, `TeamSuspension`, and the three suspension decorators (`SuspensionAwareCreditSource`, `SuspensionAwareLeadQuotaSource`, `SuspensionAwareTeamSendingGate`).
    - `Actions/` — `SuspendTeamAction`, `RestoreTeamAction`; and **19b**: `SetBillingModeAction`,
      `GrantPlanAction`, `RevokePlanGrantAction`, `SetCapabilityOverrideAction`,
      `ProvisionTeamAction`, `PublishAnnouncementAction`, `UpdatePlatformSettingsAction`,
      `DismissAnnouncementAction`.
    - `Http/Middleware/` — `EnsurePlatformAdmin`, `EnsureAdminAbility` (aliased `admin.can`), `EnsureAdminTwoFactor`, `AdminIpAllowlist`, `PlatformContext`, `ShareImpersonation`, `RestrictImpersonatedSession`.
    - `Http/Controllers/Web/` — `DashboardController`, `ResourceController` (all 40 resources), `TeamOperationsController`, `UserOperationsController`, `ImpersonationController`.
    - `Http/Requests/` — `ResourceIndexRequest`, `ResourceWriteRequest` (rules built from the descriptor), `SuspendTeamRequest`, `RestoreTeamRequest`, `AdminMemberRoleRequest`.
    - `Exceptions/AdminException`, `Providers/AdminServiceProvider`, `routes/web.php`.
    - **19b additions.** `Services/` gains `PlatformSettings` (the schema'd store),
      `SettingsStripeSwitch`, `GovernedCapabilityGate` (the third capability decorator),
      `AnnouncementBoard` (what a reader sees) and `CapabilityExplainer` (which layer decided).
      `Models/` gains `PlatformSetting`, `CapabilityOverride`, `Announcement`. `Jobs/` gains
      `AnnounceToTenantsJob`. `Data/` gains `SettingDefinition`, `PlanGrantData`,
      `ProvisionTeamData`, `ProvisionTeamPlan`. `Enums/` gains `SettingType`, `AnnouncementLevel`.
      Controllers: `SettingsController`, `TeamBillingController`, `ProvisioningController`,
      `AnnouncementController`, `AnnouncementDismissalController`.
- **Elsewhere (19b):** `Core\Enums\Capability`, `Core\Contracts\CapabilityGate`,
  `Core\Services\AllCapabilitiesEnabled`, `Core\Exceptions\CapabilityException`,
  `Core\Http\Middleware\EnsureCapability` (aliased `capability`);
  `Billing\Contracts\StripeSwitch` + `ConfiguredStripeSwitch` + `DisabledBillingGateway`,
  `Billing\Services\PlanCapabilityGate`, `Billing\Enums\BillingMode`,
  `Billing\Models\PlanGrant`, `PlanResolver::grantFor()` and `SubscriptionState::Granted`;
  `Compliance\Console\NotifyAssessmentReviewsCommand`; three `NotificationType` cases
  (`PaymentFailed`, `AssessmentReviewDue`, `Announcement`).
- **Elsewhere:** `app/Modules/Core/Support/AuditContext` (new), `AuditLogger` merges it; `Outreach\Contracts\TeamSendingGate` + `Outreach\Services\AlwaysAllowTeamSending` (new seam) and `OutreachBlockReason::TeamSuspended`; `users.admin_role`, `isPlatformAdmin()`, `adminRole()`, `hasAdminAbility()` on `App\Models\User`; `Team::isSuspended()`.
- **Routes:** `admin.dashboard`, `admin.teams.*` (show/suspend/restore/members), `admin.users.*` (show + five operations + impersonate), `admin.resources.*` (index/create/store/show/edit/update/destroy). Plus **`impersonation.stop`**, which is deliberately _not_ an `admin.*` route — see **Impersonation** below.
- **Frontend:** `resources/js/layouts/AdminLayout.vue` + `layouts/admin/AdminSidebar.vue`; `components/admin/ImpersonationBanner.vue` (mounted in `layouts/app/AppSidebarLayout.vue`, so it is on every tenant screen) and `components/admin/ResourceCell.vue`; `pages/admin/Dashboard.vue`, `pages/admin/resources/{Index,Show,Form}.vue`, `pages/admin/teams/Show.vue`, `pages/admin/users/Show.vue`; types in `resources/js/types/admin.ts` (web-only, deliberately **not** in `@/shared/types` — the mobile app has no admin surface).
- **Shared:** nothing. See the previous point.
- **Tests:** `tests/Feature/Admin/` — `AdminRouteContractTest`, `AdminAccessControlTest`, `CrossTenantReadTest`, `AdminResourceCrudTest`, `ImpersonationTest`, `TeamSuspensionTest`, `SuperAdminSeederTest`; fixtures in `tests/Support/AdminFixtures.php`.
- **Config / env:** `config/admin.php` — `ADMIN_PATH`, `ADMIN_IP_ALLOWLIST`, `ADMIN_REQUIRE_TWO_FACTOR`, `ADMIN_RATE_LIMIT`, `ADMIN_IMPERSONATION_TTL`, `ADMIN_SEED_ALLOW_PRODUCTION`.

## Data model

No new tables. Two columns on existing ones:

| Table   | Column                              | Why                                                                                                           |
| ------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `users` | `admin_role` (nullable, indexed)    | The platform identity. Not fillable, so no mass assignment anywhere in the product can grant platform powers. |
| `teams` | `suspended_at` (nullable, indexed)  | Suspension. A timestamp rather than a boolean, because "since when" is the first question anybody asks.       |
| `teams` | `suspension_reason` (nullable, 500) | And "why" is the second. Required by `SuspendTeamRequest`; **kept** when a suspension is lifted, as history.  |

**Phase 19b** adds five tables and one column, plus two tables that exist only as guards:

| Table                            | Why                                                                                                                                                                            |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `platform_settings`              | Key/value, but **schema'd by `config/admin.php`** — an undeclared key cannot be read or written. Stores deviations only: writing the declared default deletes the row.         |
| `team_plan_grants`               | A plan an operator granted, with its own `seats`/`leads`/`credits` and a window. Revoked rather than deleted, so last month's entitlement stays answerable.                    |
| `team_capability_overrides`      | One feature on or off for one tenant, with a **reason** and an optional expiry. `enabled` is a boolean rather than the row's existence, because both directions are asked for. |
| `announcements`                  | Per-locale `title`/`body` maps, a level, optional plan and market targets, a window, `is_dismissible`, `notify`, `notified_at`.                                                |
| `announcement_dismissals`        | Per **person**, not per team. One colleague clearing a banner must not hide it from the four who have not read it.                                                             |
| `announcement_notifications`     | The fan-out's per-tenant claim, with a unique index on (announcement, team). Platform bookkeeping, not tenant data — see **Telling every tenant once** below.                  |
| `teams.billing_mode`             | `stripe` / `manual` / `disabled`. A **separate axis** from entitlement: it stops checkout and metering, and grants nothing.                                                    |
| `lia_records.review_notified_at` | Compliance's column, added here: what makes the assessment-review notice survive a missed scheduler day without becoming a daily nag.                                          |

Phase 19c adds `platform_daily_metrics`.

## Key flows

### Crossing the tenancy boundary — the one to understand first

`BelongsToTeam`'s global scope is a **no-op when no team is active**. So the panel's cross-tenant reads work by _removing_ a constraint, not by adding one: `PlatformContext` clears `TeamContext`, and every tenant model is immediately unscoped.

That is exactly what an admin index needs and exactly what must never happen anywhere else, so two guards sit around it:

1. `PlatformContext` **refuses to run on a route that is not named `admin.*`** (a 500 with an explanation). Applied by accident to the leads list, it would silently serve every tenant's prospects to whoever asked.
2. `AdminRouteContractTest` asserts from the other direction: **no route outside `admin.*` may carry it**, and every route inside `admin.*` carries the whole stack and exactly one known ability. A per-route test only covers the routes somebody remembered to write one for.

Reading **one** tenant does not get a second query path. `TeamOperationsController` uses `TeamContext::run($team, …)`, so a per-team figure comes back through the same global scope and the same policies the tenant's own dashboard uses. A hand-written `where team_id` would be a second implementation of tenancy, and the day the two disagreed the panel would be the one that was wrong.

### Auditing — including reads

`AuditLog` conventionally records writes. An operator opening a tenant's prospect list is a **privacy event** — somebody outside that business read their customers' details — so it leaves a row too:

| Action                                                  | When                                                                         |
| ------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `admin.resource.viewed`                                 | An index, with the resource, filters and a count                             |
| `admin.record.viewed`                                   | One record, or an edit screen                                                |
| `admin.record.created` / `.updated` / `.deleted`        | A generic write                                                              |
| `admin.team.suspended` / `.restored`                    | A suspension applied or lifted                                               |
| `admin.user.*`                                          | Password reset sent, 2FA cleared, sessions or tokens revoked, email verified |
| `admin.impersonation.started` / `.stopped` / `.expired` | An impersonated session                                                      |

Two properties hold throughout, and both are tested:

- **Entries carry ids and counts, never rows — and never the search term.** A log of who looked at personal data must not itself be a copy of it. An update records the _names_ of the fields that changed, not the values; an index records `searched: true` rather than what was typed, because operators search by email address and by name, and `audit_logs` is append-only and outside Compliance's retention sweep. If somebody has to reproduce exactly what an operator saw, the URL is in the ticket.
- **Entries are stamped with the _target_ tenant.** This is the subtle one. `AuditLogger` reads `team_id` from `TeamContext`, which the panel has deliberately cleared — so `AdminAudit` passes the target team explicitly and writes inside `TeamContext::run()`. Without it, every admin entry would land with `team_id = null`: the row exists, nothing errors, and the per-tenant audit view — the one a customer's support ticket is answered from — shows nothing at all.

### The resource registry

One declarative `Admin\Resources\<Name>Resource` per model, listed in `config/admin.php`, rendered by three generic Inertia pages. A descriptor declares columns (reusing Reporting's `ColumnFormat`), detail-only columns, editable fields with their validation rules, searchable columns, filters, sorts, eager loads, and which of create/update/delete it permits.

Three rules keep it in shape, each enforced by a test rather than by intention:

1. **Descriptors return data.** Anything that needs to _decide_ something gets a bespoke screen. `TeamOperationsController` and `UserOperationsController` are what that looks like. A descriptor growing a conditional is the failure mode to watch for.
2. **Where a module owns the operation, the panel calls its Action.** Member removal → `RemoveTeamMemberAction`. Role change → `UpdateTeamMemberRoleAction`. (Phase 19b: plan change → `SwapPlanAction`.) The generic writer is for the rest.
3. **Everything is off by default.** `allowsCreate`, `allowsUpdate` and `allowsDelete` all return false on the base class.

Where the registry stands today:

- **Create** is permitted on exactly two resources — `icps` and `outreach-templates` — and the rule is narrow: only where **every column the table requires is a field the descriptor declares**. `lead-tags` fails that test (`slug` is derived from the name by `LeadTag::normalise()`, and deriving a column is logic), so create stays off there and the workspace's own first-use flow is the way in.
- **Delete** is permitted on exactly three — `lead-tags`, `saved-views`, `push-devices` — each a row whose only dependents cascade, and each with a real support reason. `AdminRouteContractTest` pins that list, so changing it is a failing test and a conversation rather than a quiet diff.
- **Everything else is read-only**, and the descriptors say why individually. The ones worth knowing: `outreach_suppressions` (an erasure or opt-out entry cannot be lifted — a generic delete would be a way to un-opt-out somebody), `audit_logs` (an audit trail an operator can edit records only what somebody was willing to leave behind), `ai_requests` (a team's allowance, usage meter and margin are computed from it), `lia_records` (somebody else's legal paperwork), `lead_contacts` (erasure goes through Compliance, which also suppresses the identity so the next discovery run does not re-source the person who asked to be forgotten).

The cross-tenant index over `leads` is the **only query in this product with no `team_id` in its `where` clause**, which makes `ResourceQuery` both the privacy and the performance risk. Sorts and filters are whitelisted against the descriptor, searches bind the term rather than building SQL, pagination has no "all" escape hatch, and relations are eager loaded (a dotted column without its relation declared fails the contract test, because `preventLazyLoading` would turn it into an exception).

### Suspension

`teams.suspended_at` + a required reason. What stops is **sourcing, sending and spending**; what does not stop is signing in and reading. Locking people out of data they own is the wrong lever — it punishes the colleague who did nothing — and a tenant who cannot reach their own data cannot exercise their rights over it either.

Enforced in the **guards, not in middleware**, which is ADR 0012's argument unchanged: a queued discovery job and a scheduled signal watch spend a team's allowance with no request behind them. Admin registers last in `bootstrap/providers.php` and **decorates** three bindings with `extend()`:

| Guard                                    | Decorator                        | Effect when suspended                               |
| ---------------------------------------- | -------------------------------- | --------------------------------------------------- |
| `AiPlatform\Contracts\CreditSource`      | `SuspensionAwareCreditSource`    | Allowance limit 0, usage preserved                  |
| `LeadSourcing\Contracts\LeadQuotaSource` | `SuspensionAwareLeadQuotaSource` | Quota limit 0 (not `null`, which means _unlimited_) |
| `Outreach\Contracts\TeamSendingGate`     | `SuspensionAwareTeamSendingGate` | Every sendable channel blocked with `TeamSuspended` |

`extend` rather than `bind` is the point: a bind would **replace** Billing's plan-backed source, and Admin has no business knowing what a plan is. Add a rule, do not take over.

`TeamSendingGate` is a **new seam Outreach ships** (with a permissive default, like Auth's `UnlimitedSeatLimit`), not a reuse of `LeadRestrictionResolver`. Binding over the restriction resolver would have been the shortcut, and it would have told a member that _this prospect_ may not be approached — a false statement about somebody else's legal position, recorded in the team's compliance history as though it were true. `OutreachBlockReason::TeamSuspended` is configuration rather than compliance, and its guidance says to talk to us, because it is the one refusal the team cannot fix themselves.

### Impersonation

Start is an admin route behind `admin.can:impersonate` (`super_admin` and `support` only — never `finance`, never `read_only`). **Stop is not**, and is named `impersonation.stop` rather than `admin.impersonation.stop`: by the time an operator wants out, `Auth::user()` is the customer, so an admin-gated stop route would 404 the only way back and leave them inside somebody else's session until the TTL expired. Its authorisation is the session itself.

What it is not allowed to be:

- **Not a way to act on a customer's behalf.** `config('admin.impersonation.blocked_routes')` is one list, in one file, of route-name patterns: outreach sending, every billing write, erasure and export, CSV exports, passwords, email, 2FA, passkeys, API tokens, and the team and account themselves. `RestrictImpersonatedSession` enforces it on the whole `web` and `api` surface. A per-module annotation would have been eight places to forget, and "what can't an operator do inside somebody's account?" would be a question you answered by reading all of them.
- **Not a way to become another operator.** Refused outright: it would put their powers behind this session with their name on the trail.
- **Not something that outlasts the job.** `ADMIN_IMPERSONATION_TTL` (60 minutes), ended by sign-out, and `ShareImpersonation` expires it on the next request — by **handing the operator back to themselves**, not by signing out. `SessionGuard::logout()` cycles the _authenticated_ user's `remember_token`, and at that moment the authenticated user is the customer: the obvious implementation signs a real customer out of every device they ticked "remember me" on, because an operator left a tab open. `logout` is on the blocked list for the same reason — stop impersonating first.
- **Not a way to inherit the operator's own confirmations.** The session's data survives the login, so `start()` forgets `auth.password_confirmed_at`; otherwise an impersonated session would count as password-confirmed for anything behind `RequirePassword`.
- **Not silent.** Start and stop are audited against the tenant; the banner is in the app shell so it is on every tenant screen rather than on the screens somebody remembered; and `impersonated_by` rides in `Core\Support\AuditContext`, so **every write the session makes — through whichever module's Action, none of which know impersonation exists — records the operator behind the customer**. That ambient context is the one Core change this phase needed: there is no layer between an Action and `AuditLogger` to annotate from.

### Plan-driven capabilities — one question, four answers (19b, ADR 0015)

`Core\Contracts\CapabilityGate` has one method, and it is the **only** way anything in the product
asks whether a team may use a feature. Three bindings decorate it, in provider registration order:

| Layer            | Binds                    | Adds                                            |
| ---------------- | ------------------------ | ----------------------------------------------- |
| Core (first)     | `AllCapabilitiesEnabled` | the permissive default — everything on          |
| Billing          | `PlanCapabilityGate`     | the plan's own `capabilities` list              |
| Admin (**last**) | `GovernedCapabilityGate` | suspension, the kill switch, per-team overrides |

The order is the feature, and it is asserted layer by layer and in collision by `CapabilityGateTest`:

> **suspension → global kill switch → per-team override → plan → permissive default**

Two of those would be wrong reversed. **The kill switch beats a per-team override**, because an
operator reaching for it is dealing with something broken and hunting down every team-level exception
first is how an incident becomes an outage. **An override beats the plan in both directions**,
because "give this pilot a feature their plan excludes" and "switch this off for a customer whose own
compliance team asked" are both real requests.

A plan with **no `capabilities` key at all** falls through to the permissive default. A missing
configuration is not a product decision.

Enforcement is in the **guards**, not middleware — a queued discovery job and a scheduled watch spend
a team's allowance with no request behind them. `capability:` middleware is the courtesy half, so a
member who clicks a link their plan excludes gets a sentence (402) instead of a half-rendered page.
`Capability::enforcedAt()` names the real enforcement point for every case, and
`CapabilityEnforcementTest` **walks the enum and proves each one refuses** — a capability that is
declared but not enforced is worse than one that does not exist, because the pricing page promises a
gate that is not there. Writing that test found two false claims: nothing under `/api/v1` consulted
the gate at all (so the kill switch could not stop API traffic, and a token outlived the plan that
justified it), and the insights API had no capability check although the web screen did. Both are
closed — `capability:api_access` is on the whole api group, `capability:ai_insights` on the insights
API routes, and `IssueApiTokenAction` checks before issuing.

`CapabilityExplainer` is what stops four layers becoming a guessing game: for each capability it
reports the resolved answer, what the plan alone would say, and **which layer decided**. It asks the
gate for the answer rather than re-implementing the order — the precedence has exactly one
implementation, and it is not this one.

### A plan grant outranks Stripe (19b)

`PlanResolver` consults a grant **above** a subscription:

> **grant (inside its window) → live Stripe subscription → trial → default plan**

This is what makes "turn Stripe off" and "take somebody's plan away" two different acts. An invoiced
enterprise customer has no subscription and must not be on Free; a team whose subscription says
`canceled` because of an unprocessed webhook must not lose its contracted features. A grant carries
its own numbers, because a contract says 25 seats where the price list's Enterprise row says
unlimited — and `null` on a grant means "use the plan's number", not "unlimited". Granted teams get
their own `SubscriptionState::Granted`, because the billing screen has to say something different: no
card to change, no invoices, nothing to cancel.

One grant at a time, and **one live or pending grant**, not one in force: revoking only what is
currently in force left a future-dated grant untouched, so granting Scale today and Enterprise from
next month and then Team today stacked two unrevoked rows, and which one won next month became a
tiebreak rather than a decision. The team row is locked for the swap, so two operators granting at
once cannot each revoke what they saw and insert.

`teams.billing_mode` is a **separate axis** and grants nothing. Moving a team to invoiced billing
stops checkout and stops metering — reporting overage against a subscription Stripe is not billing is
how a command logs an error every morning — and leaves entitlement alone. Which means a team moved
off Stripe **without** a grant sits on the default plan, and if it was trialling it drops there
silently when the trial lapses. Both the provisioning dry run and the mode-change toast say so.

### The Stripe switch is a product state, not an outage (19b)

`StripeSwitch` is read in three places, and each answers in a way an operator would recognise: the
container binds `DisabledBillingGateway` over the real one, so a checkout attempt is a
`BillingException` with a sentence; the billing screen does not render a button that would throw; and
`SyncSubscriptionFromStripe` **returns without processing** a webhook that arrives anyway. The
middleware that 404s the webhook route is defence in depth — this is where "switched off" has to mean
something, because a misconfigured proxy or a route cached before the switch flipped must not quietly
change a team's entitlements while an operator believes Stripe is not being listened to. It returns
rather than throws: Cashier has already answered 200, so the honest move is to leave the event
unprocessed and let the operator re-sync.

### Platform settings — a schema, not a bag of strings (19b)

Every key `platform_settings` may hold is declared in `config/admin.php` with a type, rules, a
default and help text, and **both `get()` and `set()` throw on an undeclared key**. Every key is a
literal in our own code, so an undeclared one is always a typo — and a typo that returned null was
the worst available failure here: `bool('billing.stripe_enable')` reads as false, which switches
Stripe off, and looks exactly like a working screen.

The schema is what lets the settings screen be **generated** rather than hand-written, and what lets
a new kill switch be one config entry. A setting with an `allowed` list has its items validated
against it, so `signal` for `signals` is refused rather than saved as a kill switch that kills
nothing — which is a thing you find out during the incident you reached for it in.

**A trap found and fixed here:** a setting name _contains dots_, so a validation rule keyed
`settings.billing.stripe_enabled` is read by Laravel as three levels of nesting the payload does not
have. `data_get()` returns null, `sometimes` skips the rule, and **every rule on this form was a
no-op**. `UpdateSettingsRequest` now validates each setting under a dot-free alias and maps the
errors back to the dotted name the screen indexes by.

The screen writes through `UpdatePlatformSettingsAction`, which is **one transaction** for the whole
page — each key is still its own audit entry, but a failure on the third setting no longer leaves the
first two applied. The page also shows the pending changes in words and takes a second press before
switching anything off platform-wide.

### Provisioning a business (19b)

`ProvisionTeamAction` is the Action behind the screen a sales person uses after a call. It calls
Auth's own `CreateTeamAction` rather than writing the team itself, and that is a requirement rather
than a preference: a provisioned tenant has to be indistinguishable from one that signed up, so the
Owner membership, the `team.created` audit entry and the trial-starting model event are all the ones
a signup produces.

**An operator never chooses a customer's password**, and the way in is a **password-reset link, not a
team invitation**. The plan for this phase had it the other way round and the code refused:
`InviteTeamMemberAction` exists to invite somebody to a team they are _not_ in, and `CreateTeamAction`
has just made this person the Owner. Rather than write the invitation row by hand and bypass the seat
check and audit entry that belong with it, provisioning calls 19a's own `SendUserPasswordResetAction`
after commit — so a provisioned account's first credential leaves the same trail as an operator
pressing "send reset link" on day ninety. An **existing** customer added to a second team gets
nothing; they already know how to sign in, and a "set your password" mail would read as a breach
notice.

The **dry run** is not a nicety. The first real use of this screen is somebody typing a customer's
name wrong, and the second is discovering that the owner's address already belongs to an account —
which an operator must be told _before_ a stranger is emailed into somebody else's business.

**One screen, three modules' abilities.** Provisioning is behind `teams.manage`, which `support`
holds, and it calls `GrantPlanAction` and `SetCapabilityOverrideAction`, whose own routes are behind
`billing.manage` and `settings.manage`. Until review caught it, a support operator could POST
`grant_plan=enterprise` with `capabilities_on[]=signals` and hand a tenant an unlimited plan and
feature overrides through a route that does not mention billing at all. `ProvisionTeamRequest` now
**refuses** those fields without the matching ability — refuses rather than strips, because a team
provisioned without the plan the form showed is worse than an error naming the ability needed.

### Announcements, and telling every tenant once (19b)

`AnnouncementBoard::forUser()` is asked on **every tenant page load**, which shapes the whole
implementation: the kill switch short-circuits before any query, the query is one indexed read over a
table holding a handful of rows, and the targeting and dismissals are filtered in PHP because a
`whereJsonContains` against two nullable arrays is neither portable nor indexable.

The copy is per-locale with an English fallback, and the board returns **the locale each line actually
resolved to** so the banner can mark its own `lang` and `dir` — an Arabic reader handed the English
fallback is reading LTR text inside an RTL page, and nothing else on the page knows that.

`AnnounceToTenantsJob` is the one job in the product that touches every tenant, and it is
**aggregated** in the `AnnounceTopLeadsJob` shape: one notification per _team_ through
`NotificationDispatcher::toTeam()`, never a loop over every member of every tenant. Three guards, and
they are not redundant:

- `ShouldBeUnique` on the announcement id — an operator who saves the form twice queues one fan-out.
- `announcements.notified_at` — a retry of a job that **finished** does nothing.
- `announcement_notifications`, a per-tenant claim with a unique index — the case the other two
  cannot cover. This job walks every team, so a crash at team 3,000 of 4,000 means the retry starts
  again at team 1. The insert **is** the claim, so two workers racing a chunk cannot both send, and
  the claim is taken _before_ the send: a crash between the two loses one notification, where the
  other order sends one twice. Telling every customer twice is how the next announcement becomes
  something people filter.

The stored payload carries the **resolved prose**, which is the one documented exception to the
notifications rule that a payload holds keys and params only — see `docs/modules/notifications.md`.

Retiring an announcement sets `ends_at` rather than deleting it — "when did we say the product would
be down?" is a question somebody asks later — and it leaves an already-past date alone rather than
moving the record of what we told people.

### The seeded operator

`SuperAdminSeeder` — `super@admin.com` / `password`, super admin, verified, **in no team and with no `current_team_id`**, which is the point of seeding one rather than promoting a demo user. Registered in `DatabaseSeeder` before `DemoSeeder`. It **refuses to run in production** unless both `ADMIN_SEED_ALLOW_PRODUCTION=true` and `--force`; it refreshes the role on a re-seed and **never resets a password somebody has changed**.

The account has no second factor, so its first `/admin` visit is the enrolment screen. That is correct, and it is in the runbook rather than excepted in the middleware.

## External dependencies

None. The panel reads the product's own tables.

## Permissions

Platform roles are a separate axis from `TeamRole`, which is team-scoped by definition — an operator belongs to no tenant, so there is no pivot row to answer from. The mapping lives in `config/admin.php`; `AdminServiceProvider` registers one gate per ability (`admin.<value>`) for every case in the enum, so a new ability cannot be added and left without a gate.

| Ability           | `super_admin` | `support` | `finance` | `read_only` |
| ----------------- | ------------- | --------- | --------- | ----------- |
| `platform.access` | ✅            | ✅        | ✅        | ✅          |
| `tenant.read`     | ✅            | ✅        | ✅        | ✅          |
| `tenant.write`    | ✅            | ✅        | —         | —           |
| `tenant.delete`   | ✅            | —         | —         | —           |
| `users.manage`    | ✅            | ✅        | —         | —           |
| `teams.manage`    | ✅            | ✅        | —         | —           |
| `billing.manage`  | ✅            | —         | ✅        | —           |
| `settings.manage` | ✅            | —         | —         | —           |
| `platform.write`  | ✅            | —         | —         | —           |
| `impersonate`     | ✅            | ✅        | —         | —           |
| `audit.read`      | ✅            | ✅        | ✅        | ✅          |

**Two abilities are checked on every resource route, not one.** The route declares a generic one (`tenant.read`, `tenant.write`, `tenant.delete`) and the descriptor declares its own through `AdminResource::ability()`; an operator needs both. That exists because the route ability is necessarily broad — `tenant.write` means "fix a customer's record" — and two resources are **not** a customer's record:

- `audit-logs` → `audit.read`. It is other operators' actions as well as customers'.
- `ai-prompt-templates` → `platform.write`, **super admin only**. The prompt registry is ours, and an edit lands in _every_ tenant's next AI call. Behind `tenant.write` alone, a support engineer could rewrite the system prompt for the whole platform from a screen meant for correcting a company name. `AdminRouteContractTest` pins the list of overrides, so adding or dropping one is a failing test rather than a quiet diff.

**Account operations on another operator are super-admin only** (`Admin\Support\ProtectsOperators`). `users.manage` is the support engineer's ability, and every operation behind it is safe to do _for a customer_ while none of them is safe to do _to a colleague with more power_: clearing a super admin's second factor leaves `EnsureAdminTwoFactor` sending them to re-enrol — a lower role taking the second lock off a higher one — and revoking their sessions and tokens is a denial of service against the one person who would notice.

**404 for a non-operator, 403 for an operator without the ability.** A 403 on the door confirms there is something behind it; a 404 says what a wrong URL says. Once an operator is inside, they already know the panel exists, so a 403 is the honest answer about _this_ screen.

**Two-factor authentication is required** (`ADMIN_REQUIRE_TWO_FACTOR`, default on). One super-admin credential reads every tenant's data in the product; a password alone is not the right lock. The switch exists so a test suite and a first walk through the panel do not have to solve a TOTP challenge — it is not a deployment option, and Phase 20 should assert it on in production.

## Known limitations / TODO

- **No user deletion.** Deliberately deferred, not forgotten: deleting a platform user who is a team's last Owner needs a product decision (is the team deleted, transferred, or is the delete refused?), and the panel can already remove a member from a team, suspend a tenant, and erase a _data subject_ through Compliance. The self-service account delete (`ProfileController::destroy`) is untouched.
- **Phase 19b** owns the platform settings store, the global and per-team Stripe switches, plan grants with bespoke allowances, plan-driven capabilities over Pennant, `ProvisionTeamAction`, announcements, and the `payment_failed` and `assessment_review_due` notification types.
- **Phase 19c** owns platform reporting (MRR, churn, the onboarding funnel, **gross margin per team**) over a `platform_daily_metrics` rollup, failed jobs, queue and schedule health, provider health with a breaker reset, the cross-tenant audit viewer, outreach oversight with platform suppression, and the operator views Signals (`signal_runs`), Compliance (retention runs, exports, erasures) and Billing (subscriptions, MRR, failed payments) each deferred to Phase 19.
- **Filters are equality-only, over a declared option list.** Ranges and windows belong to 19c's reporting, where figures are aggregated rather than scanned.
- **The team picker on an index is capped at 500 tenants.** Past that an operator filters by passing `team_id` in the query string, which the index accepts directly. A searchable picker is a 19c nicety.
- **A trap worth knowing, found here and fixed here:** `$this->seed(SomeSeeder::class)` in a test returns a `PendingCommand` that only runs when it is **destructed**, so an exception the seeder throws never reaches the assertion count. `DemoSeeder` had been unable to run at all for that reason. Use `$this->seed()` only when the test then asserts on what the seeder produced; to assert that a seeder _runs_, invoke it directly, as `tests/Feature/Core/DatabaseSeederTest` does.
- **MySQL indexes for the cross-tenant indexes are unproven.** The suite runs on SQLite, which says nothing about whether a query plan holds — the standing caveat for this repo. Worth a pass with production-shaped data during Phase 21.

## Changelog

| Date       | Phase | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | Files                                                                                                                                                                                                                                                                                                                                                                                         |
| ---------- | ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-28 | 0     | Module planned (Filament, ADR 0009).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | `docs/modules/admin.md`                                                                                                                                                                                                                                                                                                                                                                       |
| 2026-10-31 | 19a   | **Built.** Bespoke Inertia panel (ADR 0014, supersedes 0009): platform identity with enforced 2FA, the deliberate cross-tenant context and its route-table contract test, a 40-resource declarative registry with Action-backed writes, team suspension enforced in three guards, impersonation with a blocked-route list, and the `super@admin.com` seeder. Core gains `AuditContext`; Outreach gains the `TeamSendingGate` seam.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | `app/Modules/Admin/**`, `app/Modules/Core/Support/AuditContext.php`, `app/Modules/Outreach/{Contracts,Services,Enums}`, `config/admin.php`, `resources/js/{layouts,pages,components}/admin*`, `tests/Feature/Admin/**`                                                                                                                                                                        |
| 2026-10-31 | 19a   | **Then reviewed, and eight real defects fixed.** The `laravel-module-reviewer` and `brand-reviewer` agents (CLAUDE.md §5a) both found things, and the backend ones were not style: (1) the registry's **write** routes never consulted the descriptor's own ability, so `support` — which holds `tenant.write` — could rewrite the platform-wide **AI prompt registry** and change every tenant's next AI call; a new `platform.write` ability and a gate on store/update/destroy close it. (2) Account operations refused nothing when the target was another **operator**, so `support` could clear a super admin's second factor and leave `EnsureAdminTwoFactor` politely sending them to re-enrol — a lower role taking the second lock off a higher one; now super-admin only, via `ProtectsOperators`. (3) `Auth::logout()` during impersonation cycles the **authenticated** user's remember token, and that user is the customer: an expiring session signed a real customer out of every device they had ticked “remember me” on. Expiry now hands the operator back to themselves instead, and `logout` joined the blocked list. (4) The audit entry for an index stored the **raw search term**, and operators search by email address — so the log of who read personal data was itself accumulating it, in an append-only table outside Compliance's retention sweep; it records `searched: true` now. (5) `DatabaseSeeder` called `SuperAdminSeeder` unconditionally, so a `db:seed --force` in production aborted the whole run at the refusal and skipped everything after it. (6) The seeder would **promote** a pre-existing `super@admin.com`, which on any non-production host with open registration is a privilege escalation waiting for somebody to register the address; it now refuses an account that belongs to a team, which is what registration always creates. (7) `AuditContext` was a singleton that nothing reset, so `impersonated_by` could stamp a later request's entries in the same process — a _false attribution_ in an audit trail, not a missing field; it is `scoped`. (8) The blocked-route list had real gaps — un-suppressing a do-not-contact entry, editing the lawful-basis declaration, switching teams, accepting an invitation, spending the customer's allowance — and its `teams.store` pattern did not match `api.v1.teams.store`, because `Str::is()` is anchored. Also fixed: the panel's logo rendered Teal on Ink (a “Never” in the book, and 2.1:1) below its 120px minimum width; the impersonation banner printed the customer's local time labelled UTC, and was an English sentence with no `dir` on screens that may be Arabic; member removal was a one-click ghost button; eyebrow labels sat at 10.4px under a 12px floor; and `aria-sort` and per-row link names were missing. Three §2 layering violations went with them: five account operations became Actions with their writes and audit entries in **one** transaction, and the dashboard's and team page's queries moved into `PlatformOverview` and `TeamOverview`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | `app/Modules/Admin/{Actions,Services,Support,Http}/**`, `app/Modules/Admin/Enums/AdminAbility.php`, `app/Modules/Core/Providers/CoreServiceProvider.php`, `config/admin.php`, `database/seeders/{DatabaseSeeder,SuperAdminSeeder}.php`, `resources/js/{layouts,pages,components}/admin*`, `tests/Feature/Admin/**`, `docs/brand/brand-tokens.md` §7e                                          |
| 2026-10-31 | 19b   | **Built.** Platform settings as a schema'd store (`config/admin.php` declares every key; both `get()` and `set()` throw on an undeclared one), the global and per-team Stripe switches, plan-driven capabilities through one `CapabilityGate` decorated three times (ADR 0015), plan grants that outrank Stripe, `teams.billing_mode`, end-to-end provisioning with a dry run, and platform announcements with an aggregated per-tenant fan-out. Billing gains `StripeSwitch`, `PlanCapabilityGate`, `PlanGrant` and `SubscriptionState::Granted`; Core gains `Capability`, `CapabilityGate` and `capability:` middleware; Notifications gains three types and two producers.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `app/Modules/Admin/**`, `app/Modules/{Core,Billing,Compliance,Notifications}/**`, `bootstrap/app.php`, `config/{admin,billing}.php`, `database/migrations/2026_10_31_1900{03..09}_*`, `resources/js/pages/admin/{settings,teams,provisioning,announcements}/*`, `resources/js/components/AnnouncementBanner.vue`, `tests/Feature/Admin/**`, `tests/Feature/Notifications/ProducerGapTest.php` |
| 2026-10-31 | 19b   | **Then reviewed, and the findings were not style.** (1) **Privilege escalation through provisioning:** the screen is behind `teams.manage`, which `support` holds, and it calls `GrantPlanAction` and `SetCapabilityOverrideAction`, whose own routes need `billing.manage` and `settings.manage` — so a support operator could hand a tenant an unlimited Enterprise grant and feature overrides through a route that never mentions billing. `ProvisionTeamRequest` refuses those fields without the ability, rather than stripping them. (2) **Two capabilities claimed an enforcement point that did not exist:** nothing under `/api/v1` consulted the gate, so the kill switch could not stop API traffic and a token outlived the plan that justified it, and the insights API had no check although the web screen did. `capability:api_access` now guards the whole api group, `capability:ai_insights` the insights API, and `IssueApiTokenAction` checks before issuing — the mobile two-factor exchange has no session for middleware to hang on. The test that should have caught this only asserted `enforcedAt()` was a non-empty string; `CapabilityEnforcementTest` now walks the enum and proves each refusal. (3) **Every validation rule on the settings form was a no-op**, because a setting name contains dots and Laravel reads `settings.billing.stripe_enabled` as nesting the payload does not have — so `data_get()` returned null and `sometimes` skipped the rule. Validated under a dot-free alias now, with errors mapped back. (4) `PlatformSettings::get()` returned null for an undeclared key, so `bool('billing.stripe_enable')` silently switched Stripe off; it throws. (5) The kill-switch list took any string, so `signal` saved cleanly and killed nothing; items validate against `Capability::values()`. (6) `AnnounceToTenantsJob` set `notified_at` only after walking every tenant, so a crash at team 3,000 re-notified the first 2,999 on retry — a per-tenant claim table with a unique index fixes it, plus the `$backoff` CLAUDE.md asks for. (7) A settings save was one transaction per key, so a failure part-way left half the page applied; `UpdatePlatformSettingsAction` wraps it. (8) `CapabilityGate` was `bind`, so `extend` rebuilt the chain on every resolution and the memo and `forget()` the docblocks described did nothing; it is `scoped`. (9) `RevokePlanGrantAction` restamped `revoked_at` on an already-revoked grant, moving the date a tenant's entitlement actually ended; `retire` had the same shape. (10) `GrantPlanAction` revoked only grants _in force_, so a future-dated one stacked; it revokes every un-revoked grant under a row lock. (11) `ends_at => after:starts_at` errored on a blank `starts_at`. (12) The `billing_mode` migration claimed a `manual` or `disabled` team was "fully entitled" — it is not: without a grant it is on the default plan, which the toast and the dry run now say. Plus the assessment-review notice fired only on the exact day the threshold was crossed, so a missed scheduler morning lost it entirely (`lia_records.review_notified_at` widens the window without nagging), an N+1 and a missing `onOneServer()` in the same command, a raw table builder in `AnnouncementBoard`, and `SettingDefinition::fromConfig` silently reading a mistyped `type` as Bool. **Brand:** the announcement banner told `warning` from `critical` by colour alone (WCAG 1.4.1 — each level now has its own icon and a visually hidden word), rendered operator-authored per-locale copy with no `lang`/`dir` so an Arabic reader got right-aligned English, set 14px titles in status colour over a 12% tint of the same status (~4.1:1), and gave every banner the same "Dismiss" name; four destructive actions were single unconfirmed clicks; admin timestamps were formatted in the _viewer's team_ timezone with no label and the `datetime-local` inputs did not say the server reads them as UTC; and the "Overridden" badge spent `status.warning` on a non-problem. | `app/Modules/Admin/**`, `app/Modules/{Auth,Core,Compliance}/**`, `bootstrap/app.php`, `config/admin.php`, `database/migrations/2026_10_31_19000{8,9}_*`, `lang/{en,ar}.json`, `resources/js/**`, `tests/Feature/Admin/**`, `docs/brand/brand-tokens.md` §7f                                                                                                                                   |
