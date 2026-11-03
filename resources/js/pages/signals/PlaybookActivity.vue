<script setup lang="ts">
/**
 * The run log (S14) — and **the refusals are the point of it**.
 *
 * An automation with no audit trail is an automation nobody trusts. The questions people actually
 * ask of one are "why didn't it draft anything for Acme?" and "what would this have done last
 * week?", and a log of successes answers neither — so every step is listed with what became of it,
 * and the rows where something was refused or failed are the ones the screen leads with.
 *
 * Three things the layout follows from:
 *
 * 1. **A refusal carries the refusing rule's own words.** When the outreach guard says a prospect
 *    may not be approached, the reason, the label and the guidance all come from Outreach's own
 *    vocabulary rather than from anything Signals wrote. The screen passes them through `t()`.
 * 2. **A rehearsal is marked, not hidden.** A rule watched for a week and then switched on has a
 *    log in two halves, and the first half is the evidence somebody made the decision on.
 * 3. **It is a separate screen from the builder**, because it paginates. Together, the log would
 *    reload the whole builder on every page of history.
 *
 * Brand (§2a): plain `bg-card` rows, one filter, and `status.*` only where there is a real state to
 * report — a refusal and a failure. Nothing here is Signal Orange.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleAlert,
    CircleCheck,
    CircleDashed,
    ScrollText,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    PaginationLink,
    PlaybookStepOutcome,
    SignalPlaybookRun,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Signals', href: '/signals' },
            { title: 'Rules', href: '/signals/playbooks' },
            { title: 'Activity', href: '/signals/playbooks/activity' },
        ],
    },
});

const props = defineProps<{
    runs: {
        data: SignalPlaybookRun[];
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
    };
    filters: { attention: boolean };
}>();

const { t } = useI18n();
const { dateTime } = useFormat();

const attention = computed(() => props.filters.attention);

/** One filter, as a real URL, so a page of the log is shareable and the back button works. */
function toggleAttention(): void {
    router.get(
        '/signals/playbooks/activity',
        attention.value ? {} : { attention: 1 },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

/**
 * The glyph for a step's outcome.
 *
 * Three, not two: a refusal or a failure is the alert, a **rehearsed** step is the dashed circle,
 * and only something that really happened gets the tick. A simulated step sharing the tick read as
 * "done" when nothing was done.
 */
function stepIcon(step: PlaybookStepOutcome) {
    if (step.needs_attention) {
        return CircleAlert;
    }

    return step.status === 'simulated' || step.status === 'skipped'
        ? CircleDashed
        : CircleCheck;
}

/**
 * The guard's own words for a refused step, where it has some.
 *
 * Read off the step's `params` rather than mapped here, because *which* rule refused an approach is
 * Outreach's judgement — a second vocabulary for it on this screen would be the copy that drifts.
 */
function blockLabel(step: PlaybookStepOutcome): string | null {
    const label = step.params.block_label;

    return typeof label === 'string' && label !== '' ? label : null;
}

function blockGuidance(step: PlaybookStepOutcome): string | null {
    const guidance = step.params.block_guidance;

    return typeof guidance === 'string' && guidance !== '' ? guidance : null;
}
</script>

<template>
    <Head :title="t('What your rules have done')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="08"
            :eyebrow="t('Activity')"
            :title="t('Every decision,|including the refusals.')"
            :description="
                t(
                    'What each rule did, what it declined to do, and why. A rule that writes an approach will sometimes be refused — this is where that is said.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button variant="hero" as-child>
                    <Link href="/signals/playbooks">
                        <ArrowLeft
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                        {{ t('Rules') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <Label
            class="flex w-fit cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
        >
            <input
                type="checkbox"
                class="size-4 accent-brand"
                :checked="attention"
                data-test="attention-filter"
                @change="toggleAttention"
            />
            <span class="text-base text-foreground">
                {{ t('Only the ones worth a look') }}
            </span>
        </Label>

        <EmptyState
            v-if="!runs.data.length"
            :icon="ScrollText"
            :title="
                attention
                    ? t('Nothing has been refused or failed')
                    : t('Nothing has happened yet')
            "
            :description="
                attention
                    ? t('Every step your rules have taken went through.')
                    : t(
                          'When a rule matches a signal, what it did will appear here — including a rehearsal, which records what it would have done.',
                      )
            "
            data-test="no-runs"
        />

        <ul v-else class="space-y-3">
            <li
                v-for="run in runs.data"
                :key="run.id"
                class="space-y-3 rounded-2xl border border-border bg-card p-5"
                :data-test="`run-${run.id}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <h2
                            class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground rtl:font-bold"
                            dir="auto"
                        >
                            <Link
                                v-if="run.playbook_id"
                                href="/signals/playbooks"
                                class="rounded underline decoration-dotted underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {{ run.playbook_name }}
                            </Link>
                            <!-- The rule is gone; its name was copied onto the row so the history
                                 still reads. -->
                            <span v-else>{{ run.playbook_name }}</span>
                            <Badge v-if="run.is_rehearsal" variant="outline">
                                {{ t('Rehearsal') }}
                            </Badge>
                            <Badge v-if="!run.playbook_id" variant="outline">
                                {{ t('Rule since removed') }}
                            </Badge>
                        </h2>
                        <p
                            class="max-w-prose text-base text-muted-foreground"
                            dir="auto"
                        >
                            {{ run.signal_headline }}
                        </p>
                    </div>
                    <p
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                        dir="ltr"
                    >
                        {{ run.created_at ? dateTime(run.created_at) : '' }}
                    </p>
                </div>

                <!-- Sans, not mono: every label here is translated, and Arabic set in DM Mono
                     falls out of Tajawal into a fallback at 12px (brand-tokens.md §3). Nothing in
                     this row is a figure, so there is nothing mono was for. -->
                <dl
                    class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground"
                >
                    <div v-if="run.lead_id" class="flex gap-1.5">
                        <dt>{{ t('Lead') }}:</dt>
                        <dd>
                            <Link
                                :href="`/leads/${run.lead_id}`"
                                class="rounded text-sm text-foreground underline decoration-dotted underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                dir="auto"
                            >
                                {{ run.lead_company ?? t('Open the lead') }}
                            </Link>
                        </dd>
                    </div>
                    <!-- Whose authority it acted under, which is what explains the actor on every
                         timeline entry the steps wrote. -->
                    <div v-if="run.actor_name" class="flex gap-1.5">
                        <dt>{{ t('On behalf of') }}:</dt>
                        <dd class="text-foreground" dir="auto">
                            {{ run.actor_name }}
                        </dd>
                    </div>
                    <div v-if="run.signal_event_label" class="flex gap-1.5">
                        <dt>{{ t('Signal type') }}:</dt>
                        <dd class="text-foreground">
                            {{ t(run.signal_event_label) }}
                        </dd>
                    </div>
                </dl>

                <ol class="space-y-2">
                    <li
                        v-for="(step, index) in run.steps"
                        :key="`${run.id}-${index}`"
                        class="flex items-start gap-3"
                        :data-test="`run-${run.id}-step-${index}`"
                    >
                        <!-- Three glyphs, not two. A **simulated** step got the same tick as an
                             applied one, so a rehearsal read as "done" when nothing was done — the
                             one thing a rehearsal's log must not imply (found by
                             `brand-reviewer`). The word is in the badge beside it either way; the
                             shape is what makes the list scannable. -->
                        <component
                            :is="stepIcon(step)"
                            class="mt-0.5 size-4 shrink-0"
                            :class="
                                step.needs_attention
                                    ? 'text-warning'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <div class="min-w-0 space-y-0.5">
                            <p
                                class="flex flex-wrap items-center gap-2 text-base text-foreground"
                            >
                                {{ t(step.kind_label) }}
                                <!-- Said in a word, not only marked by the icon's colour
                                     (WCAG 1.4.1). -->
                                <Badge variant="outline">{{
                                    t(step.status_label)
                                }}</Badge>
                            </p>
                            <p
                                v-if="step.reason_label"
                                class="max-w-prose text-base text-muted-foreground"
                            >
                                {{ t(step.reason_label) }}
                            </p>
                            <!-- The guard's own words, where it refused. -->
                            <p
                                v-if="blockLabel(step)"
                                class="max-w-prose text-base text-foreground"
                                :data-test="`run-${run.id}-block-${index}`"
                            >
                                {{ t(blockLabel(step) as string) }}
                            </p>
                            <p
                                v-if="blockGuidance(step)"
                                class="max-w-prose text-base text-muted-foreground"
                            >
                                {{ t(blockGuidance(step) as string) }}
                            </p>
                            <p
                                v-else-if="step.reason_guidance"
                                class="max-w-prose text-base text-muted-foreground"
                            >
                                {{ t(step.reason_guidance) }}
                            </p>
                        </div>
                    </li>
                </ol>

                <!-- A claimed row with no steps: the chain started and did not finish. Said out
                     loud rather than rendered as an empty card, because a silent gap in an audit
                     trail is worse than a visible one. -->
                <p
                    v-if="!run.is_complete"
                    class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                    :data-test="`run-${run.id}-incomplete`"
                >
                    <CircleAlert
                        class="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    {{
                        t(
                            'This rule started on the signal and did not finish. Nothing was repeated, and the next run carried on.',
                        )
                    }}
                </p>
            </li>
        </ul>

        <Pagination
            v-if="runs.data.length"
            :links="runs.links"
            :meta="runs.meta"
            label="Activity pages"
        />
    </div>
</template>
