// Billing & Plans (Phase 16) shared types.

export type PlanKey = 'free' | 'starter' | 'team' | 'scale' | 'enterprise';

export type SubscriptionState =
    | 'none'
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'grace_period'
    | 'cancelled';

export type BillingMetric =
    | 'leads'
    | 'ai_credits'
    | 'signal_queries'
    // PL4's six. The first four are what a contact costs, the last two are rows and records
    // leaving — see `BillingMetric::isLedgered()` for why they read a different table.
    | 'people_search'
    | 'email_reveal'
    | 'phone_reveal'
    | 'email_verification'
    | 'export_rows'
    | 'crm_sync';

/**
 * One thing a team can buy outright (PL4): so many units of one metric, for a price in **minor
 * units**.
 *
 * `index` is what the "Buy more" button sends back. The browser never sees a Stripe price — a
 * request that could name one could name a cheaper one — so this carries no price id.
 */
export type TopUpOption = {
    metric: BillingMetric;
    index: number;
    quantity: number;
    amount: number;
};

/**
 * One plan from the price list.
 *
 * `amount` is in **minor units** and `null` means price on application (Enterprise) — the card
 * renders "Talk to us" rather than a missing number. `seats`, `leads` and `credits` use `null` for
 * unlimited. Both readings of null are deliberate and both belong to Enterprise.
 */
export type Plan = {
    key: PlanKey;
    name: string;
    blurb: string;
    amount: number | null;
    currency: string;
    seats: number | null;
    leads: number | null;
    credits: number | null;
    overage: boolean;
    features: string[];
    purchasable: boolean;
};

export type Subscription = {
    plan: Plan;
    state: SubscriptionState;
    state_label: string;
    needs_attention: boolean;
    is_subscribed: boolean;
    is_trialing: boolean;
    trial_ends_at: string | null;
    trial_days_left: number | null;
    ends_at: string | null;
};

/**
 * One metric's position this period, in the metric's own unit.
 *
 * `included` is what the plan covers and `ceiling` is where work stops regardless — on a metered
 * plan they differ, and the gap between them is the overage a team is allowed to run up. Both are
 * `null` on an unlimited plan.
 */
export type MetricUsage = {
    metric: BillingMetric;
    label: string;
    used: number;
    included: number | null;
    remaining: number | null;
    used_percent: number;
    ceiling: number | null;
    headroom: number | null;
    overage: number;
    overage_allowed: boolean;
    exhausted: boolean;
};

export type BillingPeriod = {
    start: string;
    end: string;
    elapsed_percent: number;
};

export type SeatUsage = {
    limit: number | null;
    members: number;
    pending: number;
    remaining: number | null;
};

export type Invoice = {
    id: string;
    number: string | null;
    date: string;
    total: number;
    currency: string;
    status: string;
    /** Stripe's own signed, expiring link to the PDF. Null on a draft. */
    pdf_url: string | null;
};

/** The per-unit overage prices, in minor units, for the "what if I go over" line. */
export type OveragePricing = {
    /**
     * What a unit of overage costs, **keyed by metric** — `leads`, `ai_credits`, `signal_queries`.
     * A map rather than a key per metric so a metric added to the price list is priced here without
     * the screen changing; `unit` is how many of the metric one billed unit covers.
     */
    metrics: Record<
        string,
        { amount: number; unit: number; label: string; price_line: string }
    >;
    currency: string;
};
