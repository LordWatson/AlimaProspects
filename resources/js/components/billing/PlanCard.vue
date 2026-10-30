<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { Plan } from '@/types';

/*
 * One plan on the price list.
 *
 * Three things the card is careful about:
 *
 *  - **It says what the plan includes in the team's own words** — seats, companies a month, AI
 *    credits — above the feature list, because those three numbers are what somebody is actually
 *    choosing between and a bulleted list buries them.
 *  - **A price of `null` is not a missing price.** Enterprise is sold by a person, so the card says
 *    "Custom" where the others say £129, and its action is an email rather than a checkout.
 *  - **Only one card is filled.** §2.3 allows one primary CTA per view, and a grid of five solid
 *    Ink buttons is the usual place that rule gets broken — so the parent names the one plan worth
 *    recommending and every other card is an outline.
 *
 * A plan that cannot be acted on shows **static text, not a disabled button**: the current plan
 * says "Your plan" and Free says "Included", neither of which is a thing to click. A Member sees no
 * button at all, with the reason named in the card rather than left to a line further up the page —
 * a greyed-out button with no explanation reads as a fault.
 *
 * Brand (brand-tokens.md §1.2, §2.1): cream surface, Deep Teal for the current plan's frame and
 * ticks. Signal Orange appears nowhere — "this is your plan" is a state.
 */
const props = defineProps<{
    plan: Plan;
    current: boolean;
    /** False for a Member, who sees the price list but cannot act on it. */
    canManage: boolean;
    /** True once the team is paying — a subscribed team swaps, an unsubscribed one checks out. */
    subscribed: boolean;
    /** The one plan worth recommending, which is the only filled button in the grid. */
    recommended: boolean;
    salesEmail: string;
    /** True while *this* card's action is in flight — not while any card's is. */
    busy?: boolean;
}>();

const emit = defineEmits<{
    (e: 'choose', plan: Plan): void;
}>();

const { t } = useI18n();
const { currency, number } = useFormat();

const price = computed(() =>
    props.plan.amount === null
        ? null
        : currency(props.plan.amount, props.plan.currency),
);

/**
 * The three numbers somebody is choosing between, each with its own "unlimited" reading.
 */
const includes = computed(() => [
    props.plan.seats === null
        ? t('Unlimited seats')
        : t(':count seats', { count: number(props.plan.seats) }),
    props.plan.leads === null
        ? t('Unlimited companies a month')
        : t(':count companies a month', { count: number(props.plan.leads) }),
    props.plan.credits === null
        ? t('Unlimited AI credits')
        : t(':count AI credits a month', { count: number(props.plan.credits) }),
]);

/** Enterprise: no checkout, so the action is a way to reach a person. */
const isByArrangement = computed(() => props.plan.amount === null);

/** Nothing to click: the plan the team is on, or one included in every plan. */
const standing = computed(() => {
    if (props.current) {
        return t('Your plan');
    }

    if (!props.plan.purchasable && !isByArrangement.value) {
        return t('Included');
    }

    return null;
});

const actionLabel = computed(() =>
    props.subscribed ? t('Switch to this') : t('Choose this plan'),
);
</script>

<template>
    <article
        class="flex flex-col gap-5 rounded-2xl border bg-card p-6 shadow-sm"
        :class="current ? 'border-brand ring-1 ring-brand' : 'border-border'"
    >
        <header class="space-y-2">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-foreground">
                    {{ t(plan.name) }}
                </h3>
                <Badge v-if="current" variant="brand">{{ t('Current') }}</Badge>
            </div>

            <p class="text-sm text-muted-foreground">{{ t(plan.blurb) }}</p>
        </header>

        <p class="flex items-baseline gap-1.5">
            <span class="text-2xl font-semibold text-foreground">
                {{ price ?? t('Custom') }}
            </span>
            <span v-if="price" class="text-sm text-muted-foreground">
                {{ t('a month') }}
            </span>
        </p>

        <ul class="space-y-1.5 text-sm text-foreground">
            <li v-for="line in includes" :key="line" class="font-medium">
                {{ line }}
            </li>
        </ul>

        <ul class="flex-1 space-y-1.5 text-sm text-muted-foreground">
            <li
                v-for="feature in plan.features"
                :key="feature"
                class="flex items-start gap-2"
            >
                <Check
                    class="mt-0.5 size-4 shrink-0 text-brand"
                    aria-hidden="true"
                />
                <span>{{ t(feature) }}</span>
            </li>
        </ul>

        <p v-if="plan.overage" class="text-xs text-muted-foreground">
            {{
                t(
                    'Go over and you pay for the extra rather than being stopped.',
                )
            }}
        </p>

        <!-- Static, because there is nothing to do: this is already the team's plan, or it is
             included in every plan. A disabled button here would read as something broken. -->
        <p
            v-if="standing"
            class="border-t border-border pt-4 text-sm font-medium text-muted-foreground"
        >
            {{ standing }}
        </p>

        <Button
            v-else-if="isByArrangement"
            as-child
            variant="outline"
            class="w-full"
        >
            <a :href="`mailto:${salesEmail}`">{{ t('Let’s talk') }}</a>
        </Button>

        <!-- A Member gets the reason rather than a greyed-out button they cannot use. -->
        <p
            v-else-if="!canManage"
            class="border-t border-border pt-4 text-sm text-muted-foreground"
        >
            {{ t('An Owner or Admin can change the plan.') }}
        </p>

        <Button
            v-else
            :variant="recommended ? 'default' : 'outline'"
            class="w-full"
            :disabled="busy"
            @click="emit('choose', plan)"
        >
            {{ actionLabel }}
        </Button>
    </article>
</template>
