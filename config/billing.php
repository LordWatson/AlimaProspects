<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Capability;

/*
|--------------------------------------------------------------------------
| Billing & Plans (Phase 16)
|--------------------------------------------------------------------------
|
| Plans live here rather than in the database: a plan is a product decision that ships with a
| release, not tenant data, and keeping it in config means a price change is a reviewed diff
| instead of an UPDATE nobody can find later. The Stripe ids below are **placeholders** until the
| products exist in Stripe — override every one of them per environment through `.env`.
|
| Money is in **minor units** (pence) throughout, as CLAUDE.md §2 requires. Allowances are in each
| metric's own unit: whole leads, and AI *credits* (1 credit = `ai-platform.credits.micros_per_credit`
| cost micro-units, so the credit figures here are directly comparable to the AI ledger).
|
*/

return [

    /*
    | Two switches, and they answer **different questions**. Getting that distinction wrong is the
    | mistake `StripeSwitch` was built to prevent, so it is spelled out here as well.
    |
    | `stripe.enabled` — *will we talk to Stripe at all?* Off means checkout, the portal, plan
    | swaps, cancellation, resumption and metered overage all refuse, and the webhook is rejected.
    | It says **nothing** about entitlement: a team on Scale stays on Scale and goes on working,
    | because `PlanResolver` reads a grant and then a subscription row, neither of which cares
    | whether we are currently willing to talk to a payment provider. The operator's own kill
    | switch (`billing.stripe_enabled`, Phase 19b) is ANDed with this, so either one turns it off.
    |
    | `enforce_plans` — *does a plan decide what a team may do?* Off means Billing does not install
    | its plan-backed capability gate or its three allowance sources at all, so the permissive
    | defaults the owning modules ship stand: every capability is allowed, lead discovery is
    | unquotaed, seats are unlimited. Off is **not** "everybody is on Free"; it is "nothing is
    | gated on a plan". Suspension still bites — that is an operator's deliberate act, not a plan
    | limit — and the AI credit allowance still applies, because that one is a cost guard every
    | tenant has had since Phase 6 rather than a plan feature (`ai-platform.credits.*`).
    |
    | Neither implies the other. Stripe off with enforcement on is the invoiced-customer case the
    | module was designed for; both off is "we are not charging anybody yet".
    */
    'stripe' => [
        'enabled' => (bool) env('BILLING_STRIPE_ENABLED', true),
    ],

    'enforce_plans' => (bool) env('BILLING_ENFORCE_PLANS', true),

    /*
    | The currency plans are priced in. Cashier's own `CASHIER_CURRENCY` drives Stripe; this is what
    | the UI formats with, and the two must agree.
    */
    'currency' => env('CASHIER_CURRENCY', 'gbp'),

    /*
    | Every new team starts a free trial of the `trial.plan` entitlements, with no card. When it
    | lapses without a subscription the team falls back to `default_plan` — it keeps its data and a
    | small working allowance, it does not get locked out.
    */
    'trial' => [
        'days' => (int) env('BILLING_TRIAL_DAYS', 14),
        'plan' => env('BILLING_TRIAL_PLAN', PlanKey::Team->value),
    ],

    'default_plan' => env('BILLING_DEFAULT_PLAN', PlanKey::Free->value),

    /*
    | How close to the allowance a team has to get before Owners and Admins are told, as the
    | percentage of the allowance still remaining. Checked when usage is synced; at most one
    | notification per metric per period per threshold, never one per request.
    */
    'alerts' => [
        'thresholds' => [20, 0],
    ],

    /*
    | Where "Talk to us" goes for Enterprise.
    */
    'sales_email' => env('BILLING_SALES_EMAIL', 'sales@alimaprospects.com'),

    /*
    | The plans themselves, cheapest first. `seats`, `leads` and `credits` are the monthly
    | entitlements; `null` means unlimited.
    |

    | **The lists start permissive, and that is a decision.** Which features a plan includes is a
    | *pricing* decision, and only two of them are ones the price list above already makes a promise
    | about: AI insights ("AI scoring, drafting and insights", Starter and up) and Signals
    | ("Signals — trigger-event prospecting", Team and up). The rest — the background check,
    | enrichment, CSV export, API access — are on **every** plan, so nothing a customer can do today
    | stops working. Tightening one is a line deleted from a plan below, which is where a pricing
    | decision belongs.
    |
    | **`capabilities` is not `features`.** `features` above is marketing copy for the pricing page;
    | `capabilities` is a list of `Core\Enums\Capability` values that gates actually consult
    | (ADR 0015). Overloading one key would have made rewording the pricing page switch a feature
    | off. A plan that declares the key is read as **exhaustive** — anything not listed is excluded;
    | a plan that omits it entirely entitles the team to everything, which is how the product
    | behaved before capabilities existed.
    |
    | Overage: a paid plan keeps working past its allowance and meters the excess to Stripe, up to
    | `overage.multiplier` x the allowance — a ceiling, because an unbounded metered plan is a way
    | to hand a customer a bill they never agreed to. Free and trial teams have no overage at all:
    | they stop, which is the whole point of a free tier.
    |
    | **`signals` is the eight figures a plan sets for Signals** (S16, `SignalQuotaSource`), and it
    | is a nested block rather than eight keys beside `leads` because they are one subject and
    | because the module reads them as one. Three are standing ceilings on instructions to spend
    | (`max_definitions`, `max_watches`, `max_watchlist_entries`), three are monthly allowances
    | (`monthly_source_queries`, `monthly_preview_queries`, `article_fetch_micros`), and two are
    | switches (`playbooks`, `webhook_source`).
    |
    | Reading it works the way `capabilities` does and not the way `leads` does: **per figure**. A
    | key a plan omits leaves the module's own floor from `config/signals.php` standing, rather than
    | becoming unlimited — because a half-written plan definition silently uncapping four dimensions
    | is a worse failure than a conservative one, and a missing configuration is not a product
    | decision.
    |
    | So a figure has **three** states and all three are used. A number is a limit. An explicit
    | `null` is a plan declaring *unlimited* and wins over the floor — which is why the Enterprise
    | plan below spells every one of them out instead of omitting the block. An **absent** key is a
    | plan that has not taken a position, and only that one reaches the floor.
    |
    | `monthly_source_queries` is the one of the eight that is also a `BillingMetric`, so it is the
    | one that is **metered** into `usage_meters`, honours `overage.multiplier` on a metered plan,
    | and warns a team at `alerts.thresholds`. The other seven are limits, not meters: nobody is
    | billed for having a fourth watchlist.
    */
    'plans' => [

        PlanKey::Free->value => [
            'name' => 'Free',
            'blurb' => 'A look around, with enough room to find your first few companies.',
            'amount' => 0,
            'stripe_price' => null,
            'seats' => 1,
            'leads' => (int) env('BILLING_FREE_LEADS', 25),
            'credits' => (int) env('BILLING_FREE_CREDITS', 2_000),
            'overage' => false,
            'capabilities' => [
                Capability::BusinessIntelligence->value,
                Capability::Enrichment->value,
                Capability::CsvExport->value,
                Capability::ApiAccess->value,
            ],
            'signals' => [
                // Signals is not on this plan at all (`capabilities` above says so), so these are
                // the floor a Free team would meet if an operator granted them the capability for
                // a pilot — which is a sales conversation, not a plan edit (ADR 0015). Deliberately
                // small: a pilot is meant to show the thing working, not to be a free tier of it.
                'max_definitions' => (int) env('BILLING_FREE_SIGNAL_TYPES', 3),
                'max_watches' => (int) env('BILLING_FREE_SIGNAL_WATCHES', 1),
                'max_watchlist_entries' => (int) env('BILLING_FREE_SIGNAL_ACCOUNTS', 10),
                'monthly_source_queries' => (int) env('BILLING_FREE_SIGNAL_QUERIES', 100),
                'monthly_preview_queries' => (int) env('BILLING_FREE_SIGNAL_PREVIEWS', 20),
                'article_fetch_micros' => (int) env('BILLING_FREE_SIGNAL_ARTICLE_MICROS', 0),
                'playbooks' => false,
                'webhook_source' => false,
            ],
            'features' => [
                'Lead discovery and scoring',
                'One seat',
                'Community support',
            ],
        ],

        PlanKey::Starter->value => [
            'name' => 'Starter',
            'blurb' => 'One person, prospecting properly.',
            'amount' => (int) env('BILLING_STARTER_AMOUNT', 4_900),
            'stripe_price' => env('STRIPE_PRICE_STARTER', 'price_alima_starter_monthly_gbp'),
            'seats' => 1,
            'leads' => (int) env('BILLING_STARTER_LEADS', 500),
            'credits' => (int) env('BILLING_STARTER_CREDITS', 20_000),
            'overage' => true,
            'capabilities' => [
                Capability::BusinessIntelligence->value,
                Capability::Enrichment->value,
                Capability::CsvExport->value,
                Capability::ApiAccess->value,
                // The price list promises “AI scoring, drafting and insights” from here up.
                Capability::AiInsights->value,
            ],
            'signals' => [
                // As Free: Signals is not on this plan, and these are what an operator's override
                // would entitle a pilot to.
                'max_definitions' => (int) env('BILLING_STARTER_SIGNAL_TYPES', 5),
                'max_watches' => (int) env('BILLING_STARTER_SIGNAL_WATCHES', 2),
                'max_watchlist_entries' => (int) env('BILLING_STARTER_SIGNAL_ACCOUNTS', 25),
                'monthly_source_queries' => (int) env('BILLING_STARTER_SIGNAL_QUERIES', 600),
                'monthly_preview_queries' => (int) env('BILLING_STARTER_SIGNAL_PREVIEWS', 60),
                'article_fetch_micros' => (int) env('BILLING_STARTER_SIGNAL_ARTICLE_MICROS', 0),
                'playbooks' => false,
                'webhook_source' => false,
            ],
            'features' => [
                'Everything in Free',
                '500 new companies a month',
                'AI scoring, drafting and insights',
                'Email support',
            ],
        ],

        PlanKey::Team->value => [
            'name' => 'Team',
            'blurb' => 'A small sales team working the same pipeline.',
            'amount' => (int) env('BILLING_TEAM_AMOUNT', 12_900),
            'stripe_price' => env('STRIPE_PRICE_TEAM', 'price_alima_team_monthly_gbp'),
            'seats' => 3,
            'leads' => (int) env('BILLING_TEAM_LEADS', 2_000),
            'credits' => (int) env('BILLING_TEAM_CREDITS', 75_000),
            'overage' => true,
            'capabilities' => [
                Capability::BusinessIntelligence->value,
                Capability::Enrichment->value,
                Capability::CsvExport->value,
                Capability::ApiAccess->value,
                Capability::AiInsights->value,
                // And “Signals — trigger-event prospecting” from here up.
                Capability::Signals->value,
                // The AI composer rides with Signals rather than above it (Signals S9, plan
                // decision #1): the library and the hand-written form are on every plan that has
                // Signals, and this is the paid shortcut to the same result. Scale and Enterprise
                // get it automatically, since they declare `Capability::values()`.
                Capability::SignalComposer->value,
            ],
            'signals' => [
                // The first plan the price list actually promises Signals on, so this is the shape
                // of the offer. The query allowance is sized from the cadence: one daily watch over
                // one place with five types of two templates is ~10 searches a day, so 2,000 is
                // room for several watches without being a number anybody has to think about —
                // while still being a number, which is the entire point of this phase.
                'max_definitions' => (int) env('BILLING_TEAM_SIGNAL_TYPES', 15),
                'max_watches' => (int) env('BILLING_TEAM_SIGNAL_WATCHES', 5),
                'max_watchlist_entries' => (int) env('BILLING_TEAM_SIGNAL_ACCOUNTS', 100),
                'monthly_source_queries' => (int) env('BILLING_TEAM_SIGNAL_QUERIES', 2_000),
                'monthly_preview_queries' => (int) env('BILLING_TEAM_SIGNAL_PREVIEWS', 200),
                // The full-article read is off by default product-wide; this is what it costs this
                // plan when an operator turns it on (`SIGNALS_ARTICLE_FETCH_ENABLED`).
                'article_fetch_micros' => (int) env('BILLING_TEAM_SIGNAL_ARTICLE_MICROS', 100_000),
                // **Both switches on, like every plan that has Signals at all.** The temptation was
                // to hold the events API back for Scale — it is a developer integration, so it
                // reads like an upmarket feature — but it shipped in S13b on every plan that had
                // Signals, and the rule `config/billing.php` already states about the capability
                // lists applies here too: nothing a customer can do today stops working, and
                // tightening one is a line changed here, which is where a pricing decision belongs.
                // Holding it back would also have taken it off the **trial** plan, so nobody could
                // evaluate it before buying.
                'playbooks' => (bool) env('BILLING_TEAM_SIGNAL_PLAYBOOKS', true),
                'webhook_source' => (bool) env('BILLING_TEAM_SIGNAL_WEBHOOK', true),
            ],
            'features' => [
                'Everything in Starter',
                'Three seats',
                '2,000 new companies a month',
                'Signals — trigger-event prospecting',
            ],
        ],

        PlanKey::Scale->value => [
            'name' => 'Scale',
            'blurb' => 'A full team, with the allowance to match.',
            'amount' => (int) env('BILLING_SCALE_AMOUNT', 37_900),
            'stripe_price' => env('STRIPE_PRICE_SCALE', 'price_alima_scale_monthly_gbp'),
            'seats' => 10,
            'leads' => (int) env('BILLING_SCALE_LEADS', 8_000),
            'credits' => (int) env('BILLING_SCALE_CREDITS', 250_000),
            'overage' => true,
            'capabilities' => Capability::values(),
            'signals' => [
                'max_definitions' => (int) env('BILLING_SCALE_SIGNAL_TYPES', 25),
                'max_watches' => (int) env('BILLING_SCALE_SIGNAL_WATCHES', 15),
                'max_watchlist_entries' => (int) env('BILLING_SCALE_SIGNAL_ACCOUNTS', 250),
                'monthly_source_queries' => (int) env('BILLING_SCALE_SIGNAL_QUERIES', 10_000),
                'monthly_preview_queries' => (int) env('BILLING_SCALE_SIGNAL_PREVIEWS', 600),
                'article_fetch_micros' => (int) env('BILLING_SCALE_SIGNAL_ARTICLE_MICROS', 500_000),
                'playbooks' => (bool) env('BILLING_SCALE_SIGNAL_PLAYBOOKS', true),
                'webhook_source' => (bool) env('BILLING_SCALE_SIGNAL_WEBHOOK', true),
            ],
            'features' => [
                'Everything in Team',
                'Ten seats',
                '8,000 new companies a month',
                'Priority support',
            ],
        ],

        PlanKey::Enterprise->value => [
            'name' => 'Enterprise',
            'blurb' => 'Bigger teams, bespoke allowances, a contract and an invoice.',
            // Price on application — there is no self-serve checkout, so no public price. A
            // subscription can still be attached to a price created per customer, which is what
            // `STRIPE_PRICE_ENTERPRISE` is for once a deal is signed.
            'amount' => null,
            'stripe_price' => env('STRIPE_PRICE_ENTERPRISE'),
            'seats' => null,
            'leads' => null,
            'credits' => null,
            'overage' => false,
            // Everything. Listed rather than omitted, because omitting the key means
            // “no declaration” and this plan's answer is a decision, not an absence.
            'capabilities' => Capability::values(),
            'signals' => [
                // Unlimited, listed rather than omitted — for the same reason `capabilities` is
                // listed here: omitting the block means "no declaration" and leaves the module's
                // own floors standing, and this plan's answer is a decision rather than an absence.
                // A signed contract's real numbers go in `.env` per customer.
                'max_definitions' => null,
                'max_watches' => null,
                'max_watchlist_entries' => null,
                'monthly_source_queries' => null,
                'monthly_preview_queries' => null,
                'article_fetch_micros' => null,
                'playbooks' => true,
                'webhook_source' => true,
            ],
            'features' => [
                'Everything in Scale',
                'Unlimited seats and allowances',
                'Security review, DPA and SLA',
                'A named contact',
            ],
        ],

    ],

    /*
    | Metered overage. One Stripe price per metric, added to the subscription as a second item and
    | billed on the same invoice. `unit` is how many of the metric one billed unit covers — leads
    | are billed singly, credits in thousands, because a per-credit line item would be unreadable.
    |
    | These two have **no default on purpose**, unlike the plan prices above. A metered price has to
    | exist in Stripe with a meter attached, and a placeholder here would be attached to every
    | checkout session as a second line item — so setting up the three plan prices and nothing else
    | would still fail, for a reason pointing at a price nobody has heard of. Unset, no metered item
    | is attached, checkout works on the plan prices alone, and overage reporting no-ops (with a
    | note in the `billing` log) until the meters exist. The multiplier still caps usage, so the
    | margin is protected in the meantime.
    */
    'overage' => [
        'multiplier' => (float) env('BILLING_OVERAGE_MULTIPLIER', 2.0),

        'leads' => [
            'stripe_price' => env('STRIPE_PRICE_OVERAGE_LEADS'),
            'unit' => 1,
            'amount' => (int) env('BILLING_OVERAGE_LEAD_AMOUNT', 8),
        ],

        'ai_credits' => [
            'stripe_price' => env('STRIPE_PRICE_OVERAGE_CREDITS'),
            'unit' => 1_000,
            'amount' => (int) env('BILLING_OVERAGE_CREDIT_AMOUNT', 120),
        ],

        /*
        | Signal searches (S16). No metered price by default, like the two above and for the same
        | reason — but note that this one's `unit` is a hundred rather than a thousand: a Serper
        | query costs us a tenth of a penny, so a per-query invoice line would be both unreadable
        | and rounded to nothing, while a thousand would be a line item most teams never reach.
        |
        | `amount` is what a hundred of them are *sold* for, not what they cost us. The margin on
        | this metric is the whole reason the per-plan allowance above exists.
        */
        'signal_queries' => [
            'stripe_price' => env('STRIPE_PRICE_OVERAGE_SIGNAL_QUERIES'),
            'unit' => 100,
            'amount' => (int) env('BILLING_OVERAGE_SIGNAL_QUERY_AMOUNT', 40),
        ],
    ],

];
