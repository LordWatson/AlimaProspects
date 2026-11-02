# ADR 0015 — Plan-driven capabilities, and a schema'd platform settings store

- **Status:** Accepted
- **Date:** 2026-10-31
- **Relates to:** [ADR 0012](0012-billing-and-plan-enforcement.md) (billing and plan enforcement), [ADR 0014](0014-admin-panel-bespoke-inertia.md) (the bespoke panel)

## Context

Phase 19b had to deliver two things the brief asked for in one sentence each, and which turn out to be the same problem twice: **"enable/disable Stripe globally and per team"**, and **"determine which features get enabled/disabled based on the plan a team is on."**

ADR 0012 already established how a plan's _numbers_ bite: seats, leads and credits are read from `config/billing.php` through `PlanResolver`, and enforced in the **guards** rather than in middleware, because a queued discovery job and a scheduled signal watch spend a team's allowance with no request behind them. What it did not answer is how a plan's _features_ bite, and that is a different question: a seat limit is a number to compare against, while a feature is a yes/no that four different parties can each have an opinion about at the same moment.

Those four parties are real, and all four showed up in the requirements:

1. **The plan.** Signals is a Team-plan feature; AI Insights starts at Starter. This is the pricing page's promise.
2. **The operator, for one tenant.** A pilot customer gets Signals on Starter for a fortnight. A customer whose own compliance team asked us to switch something off gets it switched off although they pay for it.
3. **The operator, for everybody.** The news provider is returning garbage; Signals goes off across the platform until it is fixed.
4. **Suspension.** The tenant has stopped paying, or is sending things we have had complaints about.

And the question is asked from everywhere: a controller rendering a screen, a sidebar deciding what to advertise, a queued job with no request behind it, a scheduled command with no user at all.

The naive shapes were all considered and all rejected:

- **A `features` column on `teams`.** Can express (2) but not (1): the plan's promise would be copied into every tenant row at signup and then drift from `config/billing.php` the first time pricing changed.
- **Laravel Pennant.** Installed since Phase 1 and still unused, and this is the phase where the question of using it had to be answered rather than deferred again — see below.
- **Checks at each call site.** `if ($team->plan === 'team' || $team->hasOverride('signals'))`, nine times, in nine slightly different orders. This is how one of the four parties quietly stops being consulted.

## Decision

### 1. One question, one gate, decorated three times

`Core\Contracts\CapabilityGate` has one method — "may this team use this capability?" — and it is the **only** way anything in the product asks. `Core\Enums\Capability` is the closed list of things it can be asked about.

Three bindings, in provider registration order, each wrapping the last with `$app->extend()`:

| Layer                         | Binds                    | Adds                                                   |
| ----------------------------- | ------------------------ | ------------------------------------------------------ |
| `Core` (first)                | `AllCapabilitiesEnabled` | the permissive default — everything on                 |
| `Billing`                     | `PlanCapabilityGate`     | the plan's own `capabilities` list                     |
| `Admin` (last, after Billing) | `GovernedCapabilityGate` | suspension, the global kill switch, per-team overrides |

This is the **seam pattern the codebase already uses** for `CreditSource`, `LeadQuotaSource` and `SeatLimitSource` (ADR 0012): the module that owns the question ships the contract and a permissive default, and the modules that have an opinion decorate it. The consequences are the ones that pattern always buys, and they are the reason for choosing it here:

- **No module imports `App\Modules\Admin` or `App\Modules\Billing`.** Signals asks a `CapabilityGate`; it has never heard of a plan, a grant or a suspension.
- **The product runs with the decorators absent.** A test that boots Core alone gets a working permissive gate, not a container error.
- **A new rule is a new decorator**, not an edit to every call site.

**The resolution order is itself the decision**, and it is asserted layer by layer and in collision by `tests/Feature/Admin/CapabilityGateTest.php`:

> **suspension → global kill switch → per-team override → plan → permissive default**

Two of those orderings are deliberate and would be wrong reversed:

- **The kill switch beats a per-team override.** An operator reaching for a kill switch is dealing with something broken. Having to hunt down every team-level exception first is how an incident becomes an outage.
- **A per-team override beats the plan, in both directions.** `enabled` is a boolean rather than the row's mere existence, because "give this pilot customer a feature their plan excludes" and "switch this feature off for a customer who pays for it" are both things support is asked for, and the second one has a compliance story behind it.

Overrides carry a **reason and an optional expiry**. The expiry is the point: a goodwill exception should stop being one on its own, rather than becoming permanent because nobody remembered.

### 2. Enforcement in the guards, and a missing configuration means "on"

Capabilities are checked where ADR 0012 checks allowances — in the Action or the guard, not in middleware — for the same reason: a scheduled watch has no request. `Core\Http\Middleware\EnsureCapability` exists as the **courtesy half**, so a member who clicks a link their plan excludes gets a sentence (a 402 `CapabilityException`) instead of a half-rendered page or a 500 from three layers down. It is never the only check.

Every `Capability` case must declare an `enforcedAt()` string naming the guard that enforces it, and a test asserts that none is blank. A capability that is declared but not enforced is worse than one that does not exist: the pricing page would promise a gate that is not there, and nobody would find out until a customer used a feature they had not bought.

A plan with **no `capabilities` key at all falls through to the permissive default.** A missing configuration is not a product decision, and the alternative would have switched every feature off for every customer the moment this shipped.

### 3. A plan grant outranks a live Stripe subscription

`team_plan_grants` lets an operator put a team on a plan without Stripe. `PlanResolver` consults a grant **above** a subscription, so the precedence is:

> **grant (inside its window) → live Stripe subscription → trial → default plan**

This ordering is what makes **"turn Stripe off"** and **"take somebody's plan away"** two different acts, which the brief asked for and which the obvious implementation conflates. An invoiced enterprise customer has no subscription and must not be on the Free plan; a team whose Stripe subscription says `canceled` because of a webhook we have not processed yet must not lose their contracted features. A grant also carries **its own numbers** (`seats`, `leads`, `credits`), because a signed contract says 25 seats where the price list's Enterprise row says unlimited, and `null` on a grant means "use the plan's number" rather than "unlimited".

A grant gets its own `SubscriptionState::Granted`, because the billing screen has to say something different to a granted team: there is no card to change, no invoice history and nothing to cancel.

`teams.billing_mode` (`stripe` / `manual` / `disabled`) is a **separate axis** from entitlement. Moving a team to invoiced billing stops checkout and stops usage metering — reporting overage against a subscription Stripe is not billing is how a command ends up logging an error every morning — and it does **not** change what the team may use.

### 4. The Stripe switch is a product state, not an outage

`Billing\Contracts\StripeSwitch` is read in three places, and each one answers in a way an operator would recognise:

- the container binds `DisabledBillingGateway` over `StripeBillingGateway`, so a checkout attempt is a `BillingException` with a sentence rather than an SDK error;
- the billing screen does not render a button that would throw — "Stripe is off" has to read as a product state with an explanation, not as a 503 the member meets after clicking;
- `SyncSubscriptionFromStripe` **returns without processing** a webhook that arrives anyway. The middleware that 404s the webhook route is defence in depth; this is where "switched off" has to mean something, because a misconfigured proxy or a route cached before the switch flipped must not quietly change a team's entitlements while an operator believes Stripe is not being listened to.

The listener returns rather than throws: Cashier has already answered 200 by the time it runs, so the honest move is to leave the event unprocessed and let the operator re-sync.

### 5. Platform settings are schema'd in config, and an undeclared key throws

`platform_settings` is a key/value table, and every key it may hold is **declared in `config/admin.php`** with a type, validation rules, a default and a description. `PlatformSettings::set()` on an undeclared key throws.

This is the decision that stops the table becoming the thing every key/value settings table becomes: a bag of strings nobody can enumerate, where `stripe_enabled`, `stripeEnabled` and `billing.stripe.enabled` all exist, two of them are read by nothing, and no screen can list what is configurable because nothing knows. The schema is what lets the settings screen be **generated** from the declarations rather than hand-written per setting, and what lets a kill switch be added by adding a config entry.

Settings are read through a `scoped` service that memoises for the life of a request or job, because the kill switch is consulted on page load by every tenant.

## Why not Pennant

Pennant is installed (Phase 1) and still unused, and this was the phase to decide rather than defer again. It is the obvious candidate and it is the wrong tool here, for three reasons:

1. **Pennant answers for a _scope_ it resolves itself, and caches the answer.** Our answer depends on a plan that can change by webhook, a grant with a time window, an override with an expiry and a suspension — and when any of those changes the answer must change on the next request. Pennant's stored-value model is built for the opposite case: a flag whose value should be _stable_ per scope once resolved, so that a user does not see a feature flicker between requests. Working against that means purging on every billing event, which is a cache invalidation problem we would have invented for ourselves.
2. **It has no ordering story.** Four parties with a fixed precedence is the actual requirement, and in Pennant it would live inside one resolver closure — i.e. in our code anyway, with Pennant contributing storage we do not want.
3. **The reason and the expiry are first-class here.** "Why does this customer have Signals on Starter?" is a support question with an answer, and `team_capability_overrides` stores the reason, the operator and the audit entry. A flag value of `true` does not.

Pennant remains available and appropriate for what it is good at: a rollout percentage, an A/B test, a kill switch on an experiment. A **paid** entitlement is not a feature flag — it is a term of a contract, and it belongs with the plan.

## Consequences

- There is exactly **one** way to ask whether a team may use something, and a new rule is a decorator rather than an edit to nine call sites.
- Adding a paid feature means: a `Capability` case with an `enforcedAt()`, a check in that guard, the capability on the plans that include it in `config/billing.php`, and a line in the sidebar's gating. The test suite fails if the first is declared without the second.
- Adding a kill switch or any platform-wide knob means **one entry in `config/admin.php`**; the settings screen picks it up with no frontend change.
- A team's effective plan can now differ from what Stripe says, which is the point — and it is why `SubscriptionState::Granted` exists and why the audit trail records the grant, its numbers and the operator who made it.
- **Risk accepted:** four layers means four possible reasons a feature is off, and a product where somebody has to guess which is a product nobody can support. The mitigations are that the order is fixed and tested, that every override carries a reason, and that the team's admin screen shows which layer is answering. If operators still find themselves guessing, the next step is to surface the deciding layer in the tenant-facing refusal too, not to collapse the layers.
