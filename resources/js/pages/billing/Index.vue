<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CreditCard, ExternalLink, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHero from '@/components/PageHero.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import UsageBar from '@/components/billing/UsageBar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    BillingPeriod,
    Invoice,
    MetricUsage,
    OveragePricing,
    Plan,
    SeatUsage,
    Subscription,
} from '@/types';

/*
 * Plan & billing (Phase 16).
 *
 * The screen answers three questions in the order a team asks them: **what am I on**, **what have
 * I used**, and **what else is there**. The price list is last on purpose — a billing screen that
 * opens with five plans is a billing screen selling to somebody who came to check a number.
 *
 * Two decisions worth stating:
 *
 *  - **Usage is shown before the price list, and in the metric's own words.** "1,840 of 2,000
 *    companies" is the sentence that makes an upgrade obvious; a percentage is not.
 *  - **A Member sees everything except the buttons.** When discovery stops on a Tuesday
 *    afternoon, the person it stopped for should be able to see that the month's allowance is
 *    gone rather than file a bug — but spending money stays with the Owners and Admins.
 *
 * Brand (brand-tokens.md §1.2, §2.1): cream page, Ink hero, Deep Teal for structure and the
 * current plan. Every state on this screen — trialling, payment failed, out of allowance — uses a
 * `status.*` token or Deep Teal. Signal Orange appears once, as the hero's rule, and never as a
 * status. One filled button per view (§2.3): the alert's, or — when there is no alert — the one
 * recommended plan's.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Plan & billing', href: '/billing' }],
    },
});

const props = defineProps<{
    subscription: Subscription;
    plans: Plan[];
    usage: MetricUsage[];
    period: BillingPeriod;
    seats: SeatUsage;
    invoices: Invoice[];
    overage: OveragePricing;
    sales_email: string;
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { currency, date, number } = useFormat();

/**
 * Which action is in flight, so only the button that was pressed goes quiet. A single boolean
 * disabled every plan card at once, which looks like the page has broken rather than like one
 * thing is happening.
 */
const pending = ref<string | null>(null);

const planName = computed(() => t(props.subscription.plan.name));

/** The one sentence under the hero statement: where the team stands, and what happens next. */
const standing = computed(() => {
    const sub = props.subscription;

    if (sub.state === 'past_due') {
        // Said properly in the alert below, which is where the action is. Repeating it here would
        // state the same problem twice before the reader reaches the thing that fixes it.
        return t('Allowances reset on :date.', {
            date: date(props.period.end),
        });
    }

    if (sub.state === 'grace_period' && sub.ends_at) {
        return t('Cancelled — you keep the :plan plan until :date.', {
            plan: planName.value,
            date: date(sub.ends_at),
        });
    }

    if (sub.is_trialing && sub.trial_ends_at) {
        return t(
            'You’re trying the :plan plan. :days days left, and no card needed until then.',
            {
                plan: planName.value,
                days: number(sub.trial_days_left ?? 0),
            },
        );
    }

    if (sub.state === 'none' || sub.state === 'cancelled') {
        return t(
            'You’re on the free allowance. Pick a plan when you’re ready — nothing you’ve found goes anywhere.',
        );
    }

    return t('Allowances reset on :date.', { date: date(props.period.end) });
});

/**
 * The badge beside the plan name. Deep Teal for a trial rather than `info`, whose token is still
 * pending brand-owner approval (brand-tokens.md §7.6) — a trial is a state of the subscription,
 * and Teal is the colour the rest of this screen uses for subscription structure.
 */
const stateVariant = computed(() => {
    const sub = props.subscription;

    if (sub.needs_attention) {
        return 'error' as const;
    }

    if (sub.is_trialing) {
        return 'brand' as const;
    }

    return sub.is_subscribed ? ('success' as const) : ('secondary' as const);
});

const seatLine = computed(() => {
    if (props.seats.limit === null) {
        return t(':members members — no seat limit on this plan', {
            members: number(props.seats.members),
        });
    }

    const used = props.seats.members + props.seats.pending;

    return props.seats.pending > 0
        ? t(':used of :limit seats taken, including :pending invited', {
              used: number(used),
              limit: number(props.seats.limit),
              pending: number(props.seats.pending),
          })
        : t(':used of :limit seats taken', {
              used: number(used),
              limit: number(props.seats.limit),
          });
});

/**
 * The one plan the grid recommends, and so the only filled button on it (§2.3).
 *
 * The cheapest purchasable plan above the one the team is on — which is the next step up, and the
 * only plan a recommendation can be made about without knowing what they intend to do. A team
 * already on the top plan gets no filled button, which is correct: there is nothing to recommend.
 * None at all while the past-due alert is showing, because that alert's button is the view's one
 * CTA and fixing a card beats changing a plan.
 */
const recommendedPlan = computed<string | null>(() => {
    if (!props.can.manage || props.subscription.needs_attention) {
        return null;
    }

    const order = props.plans.map((plan) => plan.key);
    const currentIndex = order.indexOf(props.subscription.plan.key);

    return (
        props.plans.find(
            (plan, index) => plan.purchasable && index > currentIndex,
        )?.key ?? null
    );
});

function overagePriceFor(usage: MetricUsage): number | null {
    return usage.metric === 'leads'
        ? props.overage.leads
        : props.overage.credits;
}

function overageUnitFor(usage: MetricUsage): number {
    return usage.metric === 'leads' ? 1 : props.overage.credits_unit;
}

function statusVariant(status: string) {
    switch (status) {
        case 'paid':
            return 'success' as const;
        case 'open':
            return 'warning' as const;
        case 'uncollectible':
            return 'error' as const;
        // `void` and `draft` are neither good news nor bad — a warning badge on a voided invoice
        // asks somebody to do something about a line that has been cancelled.
        default:
            return 'secondary' as const;
    }
}

function choose(plan: Plan): void {
    if (!props.can.manage || pending.value !== null) {
        return;
    }

    pending.value = plan.key;

    const done = { onFinish: () => (pending.value = null) };

    // A team that already pays swaps in place (prorated by Stripe); one that doesn't goes through
    // Checkout, which is also where the card is collected.
    if (props.subscription.is_subscribed) {
        router.put(
            '/billing/plan',
            { plan: plan.key },
            { preserveScroll: true, ...done },
        );

        return;
    }

    router.post('/billing/checkout', { plan: plan.key }, done);
}

function openPortal(): void {
    pending.value = 'portal';
    router.post(
        '/billing/portal',
        {},
        { onFinish: () => (pending.value = null) },
    );
}

function cancel(): void {
    if (
        !window.confirm(
            t(
                'Cancel at the end of the period you’ve paid for? Nothing stops before then.',
            ),
        )
    ) {
        return;
    }

    pending.value = 'cancel';
    router.delete('/billing/subscription', {
        preserveScroll: true,
        onFinish: () => (pending.value = null),
    });
}

function resume(): void {
    pending.value = 'resume';
    router.post(
        '/billing/subscription',
        {},
        { preserveScroll: true, onFinish: () => (pending.value = null) },
    );
}
</script>

<template>
    <Head :title="t('Plan & billing')" />

    <!-- No horizontal padding: the app shell's `<main>` already applies it, and adding more here
         would inset this screen twice. Same wrapper as `compliance/Index.vue`. -->
    <div class="flex flex-col gap-10 py-2">
        <PageHero
            :eyebrow="t('Plan & billing')"
            :title="t('What you’re on, and|what you’ve used.')"
            :description="standing"
            :meta="t('Month to :date', { date: date(period.end) })"
        />

        <!-- Payment trouble is the one thing on this screen that can't wait for somebody to
             scroll. A `div`, not a `section`: `role="alert"` would override the region role. -->
        <div
            v-if="subscription.needs_attention"
            class="flex flex-wrap items-center gap-4 rounded-2xl border border-destructive/30 bg-destructive/8 p-6"
            role="alert"
        >
            <AlertTriangle
                class="size-5 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <p class="flex-1 text-sm text-foreground">
                {{
                    t(
                        'Your last payment failed. Stripe will try again, but updating the card now is the surest fix — nothing stops in the meantime.',
                    )
                }}
                <template v-if="!can.manage">
                    {{ t('Ask an Owner or Admin to update the card.') }}
                </template>
            </p>
            <Button
                v-if="can.manage"
                size="sm"
                :disabled="pending !== null"
                @click="openPortal"
            >
                {{ t('Update payment details') }}
            </Button>
        </div>

        <!-- Where the team stands -->
        <section
            class="flex flex-col gap-6 rounded-2xl border border-border bg-card p-6"
            :aria-label="t('Your plan: :plan', { plan: planName })"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Current plan') }}
                    </p>
                    <h2 class="text-2xl font-semibold text-foreground">
                        {{ planName }}
                    </h2>
                    <p class="text-sm text-muted-foreground">{{ seatLine }}</p>
                </div>

                <Badge :variant="stateVariant">
                    {{ t(subscription.state_label) }}
                </Badge>
            </header>

            <div v-if="can.manage" class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="pending !== null"
                    @click="openPortal"
                >
                    <CreditCard class="size-4" aria-hidden="true" />
                    {{ t('Payment details and invoices') }}
                </Button>

                <Button
                    v-if="subscription.state === 'grace_period'"
                    variant="brand"
                    size="sm"
                    :disabled="pending !== null"
                    data-test="resume"
                    @click="resume"
                >
                    {{ t('Keep my subscription') }}
                </Button>

                <Button
                    v-else-if="subscription.is_subscribed"
                    variant="ghost"
                    size="sm"
                    :disabled="pending !== null"
                    data-test="cancel"
                    @click="cancel"
                >
                    {{ t('Cancel subscription') }}
                </Button>

                <Button variant="ghost" size="sm" as-child>
                    <a href="/settings/team">
                        <Users class="size-4" aria-hidden="true" />
                        {{ t('Manage the team') }}
                    </a>
                </Button>
            </div>
        </section>

        <!-- What's been used -->
        <section
            class="flex flex-col gap-6 rounded-2xl border border-border bg-card p-6"
        >
            <header class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('This month') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Allowances run with the calendar month and reset on the 1st. :percent% of the month has passed.',
                            { percent: number(period.elapsed_percent) },
                        )
                    }}
                </p>
            </header>

            <div class="grid gap-6 sm:grid-cols-2">
                <UsageBar
                    v-for="metric in usage"
                    :key="metric.metric"
                    :usage="metric"
                    :overage-price="overagePriceFor(metric)"
                    :overage-unit="overageUnitFor(metric)"
                    :currency="overage.currency"
                />
            </div>

            <p
                v-if="subscription.plan.overage"
                class="text-xs text-muted-foreground"
            >
                {{
                    t(
                        'Past your allowance you pay for the extra: :lead per company, :credit per :unit AI credits. Work stops at twice the allowance.',
                        {
                            lead: currency(overage.leads, overage.currency),
                            credit: currency(overage.credits, overage.currency),
                            unit: number(overage.credits_unit),
                        },
                    )
                }}
            </p>
        </section>

        <!-- The price list -->
        <section class="flex flex-col gap-6">
            <header class="flex flex-col gap-1">
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('Plans') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        can.manage
                            ? t(
                                  'Switch any time — you only pay the difference for the days left.',
                              )
                            : t('An Owner or Admin can change the plan.')
                    }}
                </p>
            </header>

            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <PlanCard
                    v-for="plan in plans"
                    :key="plan.key"
                    :plan="plan"
                    :current="plan.key === subscription.plan.key"
                    :can-manage="can.manage"
                    :subscribed="subscription.is_subscribed"
                    :recommended="plan.key === recommendedPlan"
                    :sales-email="sales_email"
                    :busy="pending === plan.key"
                    @choose="choose"
                />
            </div>
        </section>

        <!-- Invoices -->
        <section
            v-if="can.manage && invoices.length > 0"
            class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-6"
        >
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('Invoices') }}
            </h2>

            <ul class="divide-y divide-border">
                <li
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center justify-between gap-4 py-4"
                >
                    <div class="flex flex-col gap-0.5">
                        <p class="text-sm font-medium text-foreground">
                            {{ invoice.number ?? invoice.id }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ date(invoice.date) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        <span class="text-sm text-foreground">
                            {{ currency(invoice.total, invoice.currency) }}
                        </span>
                        <Badge :variant="statusVariant(invoice.status)">
                            {{ t(invoice.status) }}
                        </Badge>
                        <Button
                            v-if="invoice.pdf_url"
                            variant="ghost"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="invoice.pdf_url"
                                target="_blank"
                                rel="noopener"
                            >
                                <ExternalLink
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <span aria-hidden="true">{{ t('PDF') }}</span>
                                <!-- Every row's link would otherwise read "PDF", and open a new
                                     tab with no warning. -->
                                <span class="sr-only">
                                    {{
                                        t(
                                            'Invoice :number, opens in a new tab',
                                            {
                                                number:
                                                    invoice.number ??
                                                    invoice.id,
                                            },
                                        )
                                    }}
                                </span>
                            </a>
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
