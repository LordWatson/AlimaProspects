<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\PlanKey;

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
    | Overage: a paid plan keeps working past its allowance and meters the excess to Stripe, up to
    | `overage.multiplier` x the allowance — a ceiling, because an unbounded metered plan is a way
    | to hand a customer a bill they never agreed to. Free and trial teams have no overage at all:
    | they stop, which is the whole point of a free tier.
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
    ],

];
