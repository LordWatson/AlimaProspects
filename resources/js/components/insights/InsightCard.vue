<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Info, TrendingDown } from '@lucide/vue';
import { computed } from 'vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import type { BadgeVariants } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import { isolate } from '@/lib/bidi';
import type { Insight, InsightLead, InsightSeverity } from '@/types';

/*
 * One insight in the feed.
 *
 * The card leads with **what to do**, then with the companies to do it to. That order is the whole
 * point of the screen: an insight a member has to interpret is a report, and they already have a
 * dashboard for that.
 *
 * Two kinds of card, one component, and the difference is who wrote the words:
 *
 * - A **deterministic** insight (going cold, worth a call today) stores no prose. Its wording is
 *   composed here from the type and the payload, so it reads in English or Arabic depending on how
 *   the member has the app set (ADR 0007) and the numbers in the sentence are the stored ones.
 * - The **AI digest** carries a headline and a body written once, in the team's locale, and shown
 *   as written. Passing those through `t()` would be pretending a stored sentence is a key.
 *
 * ### Why no sentence here puts a number in front of a noun
 *
 * Every figure is appended after a label ("Days quiet: 3"), never embedded ("Quiet for 3 days").
 * English only needs this for "1 days"; Arabic needs it badly — يوم for one, يومان for two, أيام
 * for three to ten and يومًا from eleven, so any single string with a number before the noun is
 * wrong for most values. A label plus a number is correct for all of them, and the numbers are
 * bidi-isolated so a trailing figure cannot drag the punctuation around it in RTL.
 *
 * Colour discipline (CLAUDE.md §2a, brand-tokens.md §2.1): cream canvas, `bg-card` surface, and
 * the severity chip is the card's one accent. It uses the semantic status tokens through the badge
 * variants and **never Signal Orange**, which is decorative only and never a status colour —
 * exactly the mistake a card with a "Slipping" badge invites. The highest level is a Teal `brand`
 * chip rather than a filled-Ink one: Ink fill at this radius is the shape of a primary button
 * (`action.primary`), and a non-interactive chip reading as a control two lines above the real
 * Dismiss button is worse than a quieter accent. Severity is never carried by colour alone — each
 * chip has its own word *and* its own icon (WCAG 1.4.1).
 */
const props = defineProps<{
    insight: Insight;
    /** False for a read-only member: they see the card, not the button. */
    canDismiss: boolean;
}>();

const emit = defineEmits<{ dismiss: [insight: Insight] }>();

const { t } = useI18n();
const { date } = useFormat();

const severityVariant = computed<BadgeVariants['variant']>(
    () =>
        (
            {
                action: 'brand',
                warning: 'warning',
                info: 'info',
            } as const satisfies Record<
                InsightSeverity,
                BadgeVariants['variant']
            >
        )[props.insight.severity],
);

const severityIcon = computed(
    () =>
        (
            {
                action: ArrowRight,
                warning: TrendingDown,
                info: Info,
            } as const
        )[props.insight.severity],
);

const leads = computed<InsightLead[]>(() => props.insight.payload.leads ?? []);
const count = computed(() => props.insight.payload.count ?? leads.value.length);

/** The card's headline: the model's words, or this type's own name. */
const title = computed(() =>
    props.insight.is_written_by_ai && props.insight.headline
        ? props.insight.headline
        : t(props.insight.type_label),
);

/** The card's sentence. Labels then numbers — see the note above on plurals. */
const summary = computed(() => {
    if (props.insight.is_written_by_ai) {
        return props.insight.body ?? '';
    }

    if (props.insight.type === 'stale_leads') {
        return t(
            'Companies going quiet: :count · Days without activity: :days',
            {
                count: isolate(count.value),
                days: isolate(props.insight.payload.days ?? 0),
            },
        );
    }

    return t('Strong matches nobody has approached yet: :count', {
        count: isolate(count.value),
    });
});

const isDismissed = computed(() => props.insight.status === 'dismissed');

/**
 * Whether to show the retention countdown. On a dismissed card always — that tab is where somebody
 * goes to find a card again, so how long they have is the thing they need (compliance.md §5a) —
 * and on an open one only once it is close, so the line appears when it means something instead of
 * sitting on every card every day.
 */
const showCountdown = computed(
    () => isDismissed.value || props.insight.deletes_in_days <= 7,
);

const cardTestId = computed(() => `insight-${props.insight.id}`);
const dismissTestId = computed(() => `dismiss-insight-${props.insight.id}`);

function hintFor(lead: InsightLead): string | null {
    if (props.insight.type === 'stale_leads') {
        return lead.days_quiet == null
            ? null
            : t('Days quiet: :days', { days: isolate(lead.days_quiet) });
    }

    // An introduction-only lead is the prior-consent or suppressed case: direct marketing is
    // closed, so the card says what *is* open instead of implying an email.
    return lead.restricted ? t('Introduction only') : null;
}
</script>

<template>
    <Card as="article" :data-test="cardTestId">
        <CardContent class="flex flex-col gap-4">
            <!-- Severity, what kind of insight it is, and when it was worked out. The chip is the
                 card's one accent, and carries an icon as well as its own word. -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="severityVariant">
                        <component :is="severityIcon" aria-hidden="true" />
                        {{ t(insight.severity_label) }}
                    </Badge>
                    <span
                        v-if="insight.is_written_by_ai"
                        class="text-xs text-muted-foreground"
                        >{{ t(insight.type_label) }}</span
                    >
                </div>

                <time
                    v-if="insight.is_written_by_ai"
                    class="text-xs text-muted-foreground"
                    :datetime="insight.generated_at"
                >
                    {{ date(insight.generated_at) }}
                </time>
            </div>

            <!-- The insight itself. `dir="auto"` because the model writes in the team's language
                 and a company name can be Arabic either way. -->
            <div class="space-y-2">
                <h2
                    class="text-lg font-semibold text-foreground rtl:font-bold"
                    dir="auto"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="summary"
                    class="text-sm text-muted-foreground"
                    dir="auto"
                >
                    {{ summary }}
                </p>
            </div>

            <!-- The companies, each a link straight to the lead: the card is only useful if the
                 next click is the work. A list rather than a table — these are at most five. -->
            <ul v-if="leads.length" class="divide-y divide-border">
                <li
                    v-for="lead in leads"
                    :key="lead.id"
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2 first:pt-0 last:pb-0"
                >
                    <Link
                        :href="`/leads/${lead.id}`"
                        class="rounded text-sm font-medium text-foreground hover:text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        dir="auto"
                    >
                        {{ lead.company_name }}
                    </Link>

                    <div class="flex items-center gap-3">
                        <span
                            v-if="hintFor(lead)"
                            class="text-xs text-muted-foreground"
                            >{{ hintFor(lead) }}</span
                        >
                        <GradeBadge
                            v-if="lead.grade"
                            :grade="lead.grade"
                            :score="lead.score"
                        />
                    </div>
                </li>
            </ul>

            <div
                v-if="showCountdown || (canDismiss && !isDismissed)"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-border pt-4"
            >
                <p v-if="showCountdown" class="text-xs text-muted-foreground">
                    {{
                        t('Days until it clears: :days', {
                            days: isolate(insight.deletes_in_days),
                        })
                    }}
                </p>

                <Button
                    v-if="canDismiss && !isDismissed"
                    variant="ghost"
                    class="ms-auto"
                    :data-test="dismissTestId"
                    :aria-label="t('Dismiss :title', { title })"
                    @click="emit('dismiss', insight)"
                >
                    {{ t('Dismiss') }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
