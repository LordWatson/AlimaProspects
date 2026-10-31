<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\SettingType;
use App\Modules\Admin\Resources;
use App\Modules\Core\Enums\Capability;

/*
|--------------------------------------------------------------------------
| Admin panel (Phase 19a, ADR 0014)
|--------------------------------------------------------------------------
|
| The internal operations panel: a bespoke Inertia admin on an admin-role identity, mounted at
| `/admin` and isolated from the tenant app by a middleware stack rather than by a second auth
| guard. ADR 0014 records why it is not Filament.
|
| Two lists in this file are the panel's security surface, and both are deliberately *here* rather
| than scattered through the code, so a reviewer can answer two questions from one diff:
|
|   - `abilities` — what each operator role may do.
|   - `resources` — what an operator can see and change at all.
|
*/

return [

    /*
    | Where the panel is mounted. Changing it is not a security measure — `EnsurePlatformAdmin`
    | answers 404 to everybody else wherever it lives — but a non-default path keeps the panel out
    | of the logs of every scanner on the internet.
    */
    'path' => env('ADMIN_PATH', 'admin'),

    /*
    | Optional network restriction, empty by default. An allowlist configured wrong locks the ops
    | team out of the tool they would use to fix it, so it is opt-in and production-only in
    | practice. CIDR notation works for IPv4 and IPv6 — `10.0.0.0/8,2001:db8::/32`.
    */
    'ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')),
    ))),

    /*
    | A platform operator must have confirmed two-factor authentication. One super-admin credential
    | reads every tenant's data, and a password alone is not the right lock on that.
    |
    | The switch exists so a test suite and a first walk through the panel do not have to solve a
    | TOTP challenge. **It is not a deployment option.** Phase 20's hardening pass should assert it
    | is on in production.
    */
    'require_two_factor' => (bool) env('ADMIN_REQUIRE_TWO_FACTOR', true),

    /*
    | Requests per minute per operator, for the whole panel. Separate from the app's own limiter
    | because an operator paging through a cross-tenant index legitimately makes far more requests
    | than a member working one team — and because a compromised operator session is the one
    | credential in the product where a scripted scrape would be worth writing.
    */
    'rate_limit' => (int) env('ADMIN_RATE_LIMIT', 240),

    /*
    | `SuperAdminSeeder` plants `super@admin.com` with a known password. It refuses to run in
    | production unless this is true **and** `--force` was passed. A seeder that plants a known
    | password on a public host is not a convenience, it is the breach.
    */
    'seed' => [
        'allow_production' => (bool) env('ADMIN_SEED_ALLOW_PRODUCTION', false),
    ],

    /*
    | What each role may do. Abilities are `AdminAbility` values; `*` means all of them.
    |
    | The split is the point. Support answers tickets, so it reads tenant data, fixes records and
    | impersonates — but never deletes and never touches the platform switches. Finance needs the
    | money and nothing else, and in particular does not impersonate: there is no billing question
    | that requires being inside somebody's account. Read-only looks and touches nothing, which is
    | the right shape for an analyst, an auditor, or a new starter's first week.
    |
    | An unknown or misspelled value here is dropped rather than trusted — see `AdminRole::abilities()`.
    */
    'abilities' => [

        AdminRole::SuperAdmin->value => ['*'],

        AdminRole::Support->value => [
            AdminAbility::Access->value,
            AdminAbility::ReadTenantData->value,
            AdminAbility::WriteTenantData->value,
            AdminAbility::ManageUsers->value,
            AdminAbility::ManageTeams->value,
            AdminAbility::Impersonate->value,
            AdminAbility::ReadAudit->value,
            // Deliberately **without** `platform.write`: Support fixes a customer's record, and the
            // AI prompt registry is not one. Editing it would change every tenant's next AI call.
        ],

        AdminRole::Finance->value => [
            AdminAbility::Access->value,
            AdminAbility::ReadTenantData->value,
            AdminAbility::ManageBilling->value,
            AdminAbility::ReadAudit->value,
        ],

        AdminRole::ReadOnly->value => [
            AdminAbility::Access->value,
            AdminAbility::ReadTenantData->value,
            AdminAbility::ReadAudit->value,
        ],

    ],

    /*
    | **The platform settings schema** (Phase 19b, ADR 0015).
    |
    | Every operational switch is declared here with its type, its default, and whether it is one an
    | operator reaches for during an incident. `platform_settings` holds only **overrides**: with no
    | row, the answer is the default below, so a fresh deployment behaves exactly as `.env` says.
    |
    | A key that is not declared here cannot be read or written — which is what stops the table
    | becoming a junk drawer of untyped strings, and what makes `PlatformSettings::get()` able to
    | cast a JSON column back to the shape it promised.
    |
    | Why a settings table at all, when `.env` exists: an operator has to be able to turn Stripe off
    | at 2am from a browser, and a deploy is not a control surface.
    */
    'settings' => [

        'billing.stripe_enabled' => [
            'type' => SettingType::Bool->value,
            // Defaults to on, and this switch is the **only** thing that turns it off.
            // `ConfiguredStripeSwitch` deliberately does not read `cashier.secret`: "Stripe is
            // configured" and "Stripe is switched on" are different questions, and answering the
            // second with the first reported Stripe as off for the whole test suite (which clears
            // the key on purpose). A deployment with no keys is a misconfiguration Stripe's own
            // error states more clearly than we can.
            'default' => true,
            'label' => 'Stripe payments',
            'help' => 'Off stops checkout, the portal, plan changes, cancellations, metered overage '
                .'and the webhook. It does NOT change anybody\'s plan: a team on Scale stays on '
                .'Scale with its allowances intact. Use it when Stripe is misbehaving or during a '
                .'billing migration.',
            'operational' => true,
        ],

        'capabilities.disabled' => [
            'type' => SettingType::StringList->value,
            'default' => [],
            'label' => 'Globally disabled features',
            'help' => 'Kill switches, for the afternoon a provider is down or something ships '
                .'broken. Beats per-team overrides on purpose — an operator dealing with an incident '
                .'should not have to find every exception first. Values: '
                .implode(', ', Capability::values()).'.',
            'operational' => true,
            // The closed list. Without it, `signal` instead of `signals` saves cleanly, kills
            // nothing, and leaves an operator believing they have switched a broken feature off.
            'allowed' => Capability::values(),
        ],

        'announcements.enabled' => [
            'type' => SettingType::Bool->value,
            'default' => true,
            'label' => 'Show announcements',
            'help' => 'Off hides every announcement banner from every tenant without deleting any '
                .'of them — the lever for a banner that turns out to be wrong.',
            'operational' => true,
        ],

        'provisioning.trial_days' => [
            'type' => SettingType::Int->value,
            // Deliberately reads the billing default rather than restating it: two numbers that
            // mean the same thing drift, and the one in `config/billing.php` is the product's.
            'default' => (int) env('BILLING_TRIAL_DAYS', 14),
            'label' => 'Trial length for provisioned teams (days)',
            'help' => 'What a team gets when sales onboards it from the panel without setting a '
                .'trial explicitly. Zero means no trial, which is right for an invoiced customer '
                .'whose contract already started.',
            'operational' => false,
        ],

    ],

    /*
    | Impersonation.
    */
    'impersonation' => [

        /*
        | How long a session lasts before the clock ends it. A forgotten impersonation is a
        | customer's account left open in somebody's browser.
        */
        'ttl_minutes' => (int) env('ADMIN_IMPERSONATION_TTL', 60),

        /*
        | **The hard rule, as a list.** An impersonated session may not do anything that leaves the
        | building or that the customer cannot undo. Route-name patterns, matched with `Str::is()`,
        | enforced on the whole `web` and `api` surface by `RestrictImpersonatedSession`.
        |
        | One list in one file, rather than a middleware on eight modules' route files: a per-module
        | annotation would be eight places to forget, and "what can't an operator do inside somebody's
        | account?" would be a question you answered by reading all of them.
        |
        | Patterns are matched with `Str::is()`, which is **anchored** — so a name that also exists
        | under `api.v1.` is written with a leading `*`.
        |
        | The `api.v1.*` reach is **defence in depth, not a live path.** `/api/v1` is token-only
        | today (there is no `statefulApi()` and no session in the api group), so an impersonated
        | browser session cannot authenticate there at all and `RestrictImpersonatedSession` on that
        | group never fires. The patterns and the middleware stay because the day somebody makes the
        | API session-stateful for a first-party client is the day the restriction has to already be
        | there, rather than be remembered.
        */
        'blocked_routes' => [

            // Transmitting. An operator in a customer's seat emailing a prospect is a message the
            // customer did not write, from their domain, to a real person — and it would land in
            // their suppression and consent record as though they had.
            '*outreach.messages.send',

            // **Un-suppressing somebody.** The same class of act as a send, and arguably worse: it
            // removes a do-not-contact entry with the customer's name on it, in the record that is
            // their evidence of having honoured an opt-out.
            '*outreach.suppressions.destroy',

            // Money. Viewing the billing page is fine; spending on it is not.
            'billing.checkout',
            'billing.portal',
            'billing.swap',
            'billing.cancel',
            'billing.resume',

            // Spending the customer's allowance — the same rationale as the billing writes, one
            // step further down. A discovery run and a signal watch both cost them leads and
            // credits, and an operator demonstrating the product should not do it out of their
            // budget.
            '*lead-sourcing.store',
            '*signal-watches.run',

            // Irreversible, and the data controller's decision rather than ours. The controller is
            // the tenant. `lia.update` as well as `lia.withdraw`: the declaration *is* the legal
            // record, and editing one is signing somebody else's paperwork.
            'compliance.dsar.erase',
            'compliance.export.store',
            'compliance.lia.update',
            'compliance.lia.withdraw',
            'compliance.retention.update',

            // Data leaving on an operator's laptop. Reading a tenant's prospects in the panel is an
            // audited privacy event; downloading them is a copy we no longer control.
            'leads.export',
            'reports.export',

            // Credentials. Changing any of these while wearing somebody's account is how an
            // impersonation becomes a way to keep the account.
            'user-password.update',
            'password.*',
            'two-factor.*',
            'passkey.*',
            'api-tokens.*',
            'api.v1.auth.token.*',
            'api.v1.auth.tokens.*',

            // The account and the team itself. An operator who needs to change these does it in the
            // panel as themselves, where the audit trail carries their name rather than the
            // customer's.
            'profile.update',
            'profile.destroy',
            'team.update',
            'team.members.*',
            'team.invitations.*',
            'invitations.accept',
            // `*` prefixes, because `Str::is()` is anchored: a bare `teams.store` would not match
            // `api.v1.teams.store`, and the two are the same act.
            '*teams.store',
            '*teams.switch',

            // **Signing out.** Not a restriction for its own sake: `Auth::logout()` cycles the
            // *authenticated* user's remember token, and while impersonating that user is the
            // customer — so "log out" would sign them out of their own remembered devices and
            // leave no `stopped` entry behind. Stop impersonating first; it is one click, and it is
            // on every screen.
            'logout',
        ],

    ],

    /*
    | **Every resource the panel exposes.** This list is the answer to "what can an operator see and
    | change?" — one file, readable in a minute, diffed in review. A registry that discovered its
    | descriptors by scanning the namespace would answer the same question only by running the
    | application, and would quietly pick up whatever somebody left half-written.
    |
    | Each class declares its own columns, fields, filters and — crucially — which of create, update
    | and delete it permits. All three default to **off**; see `AdminResource`.
    */
    'resources' => [

        // Tenants & people
        Resources\TeamResource::class,
        Resources\UserResource::class,
        Resources\TeamInvitationResource::class,
        Resources\BusinessProfileResource::class,
        Resources\OnboardingProgressResource::class,

        // Prospecting
        Resources\IcpResource::class,
        Resources\IcpCriterionResource::class,
        Resources\DiscoveryRunResource::class,
        Resources\LeadResource::class,
        Resources\LeadContactResource::class,
        Resources\LeadScoreResource::class,
        Resources\ScoreFeedbackResource::class,

        // Workspace
        Resources\PipelineStageResource::class,
        Resources\LeadNoteResource::class,
        Resources\LeadTaskResource::class,
        Resources\LeadActivityResource::class,
        Resources\LeadTagResource::class,
        Resources\LeadAssignmentResource::class,
        Resources\SavedViewResource::class,

        // Signals
        Resources\SignalWatchResource::class,
        Resources\SignalRunResource::class,
        Resources\SignalResource::class,

        // Outreach
        Resources\OutreachTemplateResource::class,
        Resources\OutreachMessageResource::class,
        Resources\ContactAttemptResource::class,
        Resources\OutreachSuppressionResource::class,

        // Engagement
        Resources\NotificationResource::class,
        Resources\NotificationPreferenceResource::class,
        Resources\NotificationSettingResource::class,
        Resources\PushDeviceResource::class,

        // Intelligence
        Resources\InsightResource::class,
        Resources\InsightRunResource::class,
        Resources\BusinessIntelReportResource::class,
        Resources\AiPromptTemplateResource::class,
        Resources\AiRequestResource::class,

        // Compliance
        Resources\RetentionPolicyResource::class,
        Resources\LiaRecordResource::class,

        // Billing
        Resources\SubscriptionResource::class,
        Resources\UsageMeterResource::class,
        Resources\PlanGrantResource::class,
        Resources\CapabilityOverrideResource::class,

        // Platform
        Resources\AuditLogResource::class,
        Resources\AnnouncementResource::class,
        Resources\PlatformSettingResource::class,
    ],

];
