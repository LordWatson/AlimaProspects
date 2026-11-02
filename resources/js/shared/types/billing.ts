// Billing & Plans (Phase 16) shared types.

export type PlanKey = 'free' | 'starter' | 'team' | 'scale' | 'enterprise';

export type SubscriptionState =
    | 'none'
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'grace_period'
    | 'cancelled';

export type BillingMetric = 'leads' | 'ai_credits';

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
    leads: number;
    credits: number;
    credits_unit: number;
    currency: string;
};
