<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import { Button } from '@/components/ui/button';
import type { MetricUsage, TopUpOption } from '@/types';

/*
 * One metered allowance: what has been used, what is left, and what happens at the end.
 *
 * The bar carries **two marks, not one**. The fill is consumption; the hairline past it is where
 * the included allowance ends, so the stretch beyond it is the overage a metered plan is allowed
 * to run up before work stops. A single bar would have to choose between the two, and whichever
 * it chose would be a lie on the other kind of plan — a Starter team at 110% would read as "full"
 * when it is working and being billed, or a Free team at 100% would read as "nearly there" when it
 * has already stopped.
 *
 * The bar is `aria-hidden`, because it is a picture of the sentences beside and under it — and so
 * **both of its marks have to be in those sentences**: the summary names the allowance *and* the
 * ceiling on a metered plan, which is exactly what the fill and the hairline show.
 *
 * Brand (brand-tokens.md §2.1): the fill is Deep Teal, the warning and stopped states use the
 * `status.*` tokens, and Signal Orange appears nowhere — this is state from end to end. The
 * hairline is `ink-60`, which clears the 3:1 non-text minimum against the track; an `ink/40` wash
 * would be about 2:1 and is not a mark somebody can see.
 */
const props = defineProps<{
    usage: MetricUsage;
    /** Price per billed overage unit, minor units. */
    overagePrice?: number | null;
    /** How many of the metric one billed unit covers (1 for leads, 1,000 for credits). */
    overageUnit?: number;
    currency?: string;
    /** What more of this can be bought in (PL4). Empty for a metric that isn't sold that way. */
    topups?: TopUpOption[];
    /** A purchase is in flight somewhere on the page, so no button on it should take a second press. */
    buying?: boolean;
}>();

const emit = defineEmits<{ buy: [option: TopUpOption] }>();

const { t } = useI18n();
const { number, currency: money } = useFormat();

const unlimited = computed(() => props.usage.included === null);

/** Whether this plan meters past its allowance, and so has a ceiling beyond it to draw. */
const metered = computed(
    () =>
        props.usage.overage_allowed &&
        props.usage.included !== null &&
        !!props.usage.ceiling &&
        props.usage.ceiling > props.usage.included,
);

/**
 * Where the included allowance ends, on a bar scaled to the ceiling. Null when there is no
 * stretch past the allowance to mark.
 */
const allowanceMark = computed(() => {
    if (!metered.value) {
        return null;
    }

    const { included, ceiling } = props.usage;

    return Math.min(100, Math.round(((included ?? 0) / (ceiling ?? 1)) * 100));
});

/**
 * The fill, 0–100. Scaled to the ceiling where there is one past the allowance, so the overage a
 * team is running up is visible rather than pinned at the end; otherwise scaled to the allowance.
 */
const fill = computed(() => {
    if (!metered.value) {
        return Math.min(100, props.usage.used_percent);
    }

    const { used, ceiling } = props.usage;

    return Math.min(100, Math.round((used / (ceiling ?? 1)) * 100));
});

const tone = computed(() => {
    if (props.usage.exhausted) {
        return 'bg-destructive';
    }

    if (props.usage.overage > 0) {
        return 'bg-warning';
    }

    return 'bg-brand';
});

/**
 * The line beside the label. On a metered plan it names the ceiling as well as the allowance,
 * because that is the hairline the bar draws and the bar itself is hidden from assistive tech.
 */
const summary = computed(() => {
    if (unlimited.value) {
        return t(':used used — no limit on this plan', {
            used: number(props.usage.used),
        });
    }

    if (metered.value) {
        return t(':used of :included used, and up to :ceiling allowed', {
            used: number(props.usage.used),
            included: number(props.usage.included ?? 0),
            ceiling: number(props.usage.ceiling ?? 0),
        });
    }

    return t(':used of :included used', {
        used: number(props.usage.used),
        included: number(props.usage.included ?? 0),
    });
});

const note = computed(() => {
    if (props.usage.exhausted) {
        return t('Nothing left this month.');
    }

    if (props.usage.overage > 0) {
        const unit = props.overageUnit ?? 1;
        const billable = Math.floor(props.usage.overage / unit);

        if (props.overagePrice && billable > 0) {
            return t(':over over — about :cost on your next invoice.', {
                over: number(props.usage.overage),
                cost: money(billable * props.overagePrice, props.currency),
            });
        }

        return t(':over over your allowance.', {
            over: number(props.usage.overage),
        });
    }

    if (unlimited.value) {
        return null;
    }

    return t(':remaining left.', {
        remaining: number(props.usage.remaining ?? 0),
    });
});

/**
 * Buying more is offered **alongside** the bar, not only once it is empty.
 *
 * A button that appeared at zero would be a button somebody meets mid-task, having already been
 * stopped; one that is always there is a price list. It is the honest place for it too, because a
 * top-up changes `included` — so the bar beside it is what the purchase moves.
 *
 * The visible label leaves the metric out, because the bar's own heading is right above it. The
 * **accessible** label puts it back in and contains the visible text word for word (WCAG 2.5.3):
 * six of these bars on one screen means six buttons reading "Buy 250 more for £15", and a buttons
 * list or a tab through them is where that becomes a guess.
 */
const buyable = computed(() => props.topups ?? []);
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
            <p class="font-medium text-foreground">{{ t(usage.label) }}</p>
            <p class="text-sm text-muted-foreground">{{ summary }}</p>
        </div>

        <div
            v-if="!unlimited"
            class="relative h-2 overflow-hidden rounded-full bg-muted"
            aria-hidden="true"
        >
            <div
                class="h-full rounded-full transition-[width] duration-300"
                :class="tone"
                :style="{ width: `${fill}%` }"
            />
            <!-- Where the included allowance ends. Past this line the team is still working, and
                 paying for it. Named in `summary` too, since the bar is hidden. -->
            <div
                v-if="allowanceMark !== null"
                class="absolute inset-y-0 w-px bg-ink-60"
                :style="{ insetInlineStart: `${allowanceMark}%` }"
            />
        </div>

        <p
            v-if="note"
            class="text-sm"
            :class="
                usage.exhausted
                    ? 'text-destructive'
                    : usage.overage > 0
                      ? 'text-warning'
                      : 'text-muted-foreground'
            "
        >
            {{ note }}
        </p>

        <!-- One button per option. A `<Button>` rather than a link: it POSTs, because pressing it
             creates a Checkout session at Stripe. -->
        <div v-if="buyable.length > 0" class="flex flex-wrap gap-2 pt-1">
            <Button
                v-for="option in buyable"
                :key="option.index"
                variant="secondary"
                size="sm"
                :disabled="buying"
                :aria-busy="buying"
                :aria-label="
                    t('Buy :quantity more :metric for :cost', {
                        quantity: number(option.quantity),
                        metric: t(usage.label),
                        cost: money(option.amount, currency),
                    })
                "
                @click="emit('buy', option)"
            >
                {{
                    t('Buy :quantity more for :cost', {
                        quantity: number(option.quantity),
                        cost: money(option.amount, currency),
                    })
                }}
            </Button>
        </div>
    </div>
</template>
