<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ExternalLink, Flag, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfidencePill from '@/components/leads/ConfidencePill.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { EvidenceVerdict, Lead, LeadEvidence } from '@/types';

/*
 * Why this lead, and why now (PL1).
 *
 * The panel's shape is the feature's one safety property: **the summary never appears without the
 * evidence it was written from.** The sentence is AI-written over the items below it and nothing
 * else, and the prompt and its schema say so — but a reader cannot check a prompt, and they can
 * check a dated headline with the outlet's name on it. So the facts are always here, and the
 * sentence is labelled as Alima's reading of them rather than presented as a fact of its own.
 *
 * Three more decisions, each the same argument the signal feed already settled:
 *
 *  - **No status colour anywhere.** Confidence is a statement about our data, not a verdict on the
 *    company (see `ConfidencePill`), and an item's weight is a magnitude — so the strength bar is
 *    a neutral track with no hue. `status.*` would be wrong even in the right colour: nothing here
 *    reports that something is fine or broken.
 *  - **Both dates, where they differ.** "Happened 12 March, found 2 April" is the honest answer to
 *    "is this fresh?", and collapsing them to one date is how a slow crawl comes to look like
 *    today's news.
 *  - **A disputed item stays on the panel, struck through.** Removing it would hide the fact that
 *    somebody checked — and the next member to open the lead would wonder where the reason went.
 *
 * Outbound links carry `rel="noopener nofollow"`: these are strangers' pages, and we are not
 * vouching for them.
 */
const props = defineProps<{
    lead: Lead;
    canFlag: boolean;
}>();

const { t } = useI18n();
const { date, relative } = useFormat();

const items = computed<LeadEvidence[]>(() => props.lead.evidence ?? []);

/** The freshest thing we know, for the panel's one-line "as at" note. */
const freshest = computed(() => {
    const dates = items.value
        .map((item) => item.observed_at ?? item.captured_at)
        .sort();

    return dates.length > 0 ? dates[dates.length - 1] : null;
});

const verdicts: { value: EvidenceVerdict; label: string }[] = [
    { value: 'wrong', label: 'This is wrong' },
    { value: 'outdated', label: 'Out of date' },
    { value: 'irrelevant', label: 'Not relevant to us' },
    { value: 'useful', label: 'Useful' },
];

/**
 * One form, reused for whichever item is being flagged. A form per row would be forty forms on a
 * busy lead, and only ever one of them is in flight.
 */
const flagging = ref<number | null>(null);
const form = useForm<{ verdict: EvidenceVerdict | null; note: string | null }>({
    verdict: null,
    note: null,
});

function flag(item: LeadEvidence, verdict: EvidenceVerdict): void {
    flagging.value = item.id;
    form.verdict = verdict;

    form.post(`/leads/${props.lead.id}/evidence/${item.id}/feedback`, {
        preserveScroll: true,
        onFinish: () => {
            flagging.value = null;
        },
    });
}

/** Translated here rather than in the loop, so a verdict reads the same wherever it is shown. */
function verdictLabel(verdict: EvidenceVerdict): string {
    const match = verdicts.find((candidate) => candidate.value === verdict);

    return match ? t(match.label) : verdict;
}
</script>

<template>
    <Card data-test="why-now">
        <CardHeader class="gap-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <CardTitle>{{ t('Why now') }}</CardTitle>
                <ConfidencePill
                    :confidence="lead.confidence"
                    :band="lead.confidence_band"
                    size="md"
                />
            </div>
            <p v-if="freshest" class="text-xs text-muted-foreground">
                {{
                    t('Freshest thing we know: :when', {
                        when: relative(freshest),
                    })
                }}
            </p>
        </CardHeader>

        <CardContent class="space-y-5">
            <!-- Alima's reading of the facts below. Labelled as such, and never shown on its
                 own: the evidence is always underneath it. -->
            <div v-if="lead.why_now_summary" class="space-y-1">
                <p
                    class="flex items-center gap-1.5 font-mono text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <Sparkles class="size-3" aria-hidden="true" />
                    {{ t('Alima’s read') }}
                </p>
                <p class="text-sm leading-relaxed" dir="auto">
                    {{ lead.why_now_summary }}
                </p>
            </div>

            <!-- Nothing yet. Two different situations, and the member should be able to tell
                 them apart: no reasons at all, versus reasons but no sentence written yet. -->
            <p v-if="!items.length" class="text-sm text-muted-foreground">
                {{
                    t(
                        'Alima hasn’t found a reason to contact this company yet. Reasons arrive with discovery, enrichment and your signal watches.',
                    )
                }}
            </p>

            <ol v-else class="space-y-4">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="border-s-2 border-border ps-3"
                    :data-test="`evidence-${item.id}`"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0 space-y-1">
                            <p
                                class="text-sm font-medium"
                                :class="
                                    item.is_disputed
                                        ? 'text-muted-foreground line-through'
                                        : ''
                                "
                                dir="auto"
                            >
                                {{ item.title }}
                            </p>
                            <p
                                v-if="item.summary"
                                class="text-xs text-muted-foreground"
                                dir="auto"
                            >
                                {{ item.summary }}
                            </p>
                        </div>

                        <!-- Flagging is work, not reading: Read-only members see the panel and
                             no menu. -->
                        <DropdownMenu v-if="canFlag">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :disabled="flagging === item.id"
                                    :aria-label="
                                        t('Flag “:title”', {
                                            title: item.title,
                                        })
                                    "
                                    :data-test="`flag-${item.id}`"
                                >
                                    <Flag class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-for="verdict in verdicts"
                                    :key="verdict.value"
                                    :data-test="`flag-${item.id}-${verdict.value}`"
                                    @select="flag(item, verdict.value)"
                                >
                                    {{ t(verdict.label) }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <div
                        class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
                    >
                        <!-- The kind, as the row's one chip. A trigger event is the thing that
                             makes a lead urgent, so it is the only kind that says so in a tinted
                             badge; everything else is an outline. -->
                        <Badge
                            :variant="item.is_timing ? 'lime' : 'outline'"
                            class="text-xs"
                        >
                            {{ t(item.kind_label) }}
                        </Badge>

                        <!-- Never a translation key: this is an outlet's or a register's own name. -->
                        <span class="text-muted-foreground" dir="auto">{{
                            item.source_label
                        }}</span>

                        <span
                            v-if="item.observed_at"
                            class="text-muted-foreground"
                        >
                            {{ date(item.observed_at) }}
                        </span>

                        <!-- Only when it differs from the event's own date, which is when the gap
                             is worth knowing about. -->
                        <span
                            v-if="
                                item.observed_at &&
                                date(item.captured_at) !==
                                    date(item.observed_at)
                            "
                            class="text-muted-foreground"
                        >
                            {{
                                t('found :when', {
                                    when: relative(item.captured_at),
                                })
                            }}
                        </span>
                        <span
                            v-else-if="!item.observed_at"
                            class="text-muted-foreground"
                        >
                            {{
                                t('found :when', {
                                    when: relative(item.captured_at),
                                })
                            }}
                        </span>

                        <a
                            v-if="item.source_url"
                            :href="item.source_url"
                            target="_blank"
                            rel="noopener nofollow"
                            class="inline-flex items-center gap-1 underline underline-offset-2 hover:text-foreground"
                        >
                            {{ t('Source') }}
                            <ExternalLink class="size-3" aria-hidden="true" />
                        </a>
                    </div>

                    <!-- How much this reason is carrying today, as a neutral magnitude. No hue,
                         no letter, no grade: it is a weight, not a verdict. -->
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <span
                            class="h-1 w-12 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <span
                                class="block h-full rounded-full bg-foreground/60"
                                :style="{ width: `${item.weight}%` }"
                            />
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                item.is_disputed
                                    ? t('Flagged wrong — no longer counted')
                                    : t('Strength :weight of 100', {
                                          weight: String(item.weight),
                                      })
                            }}
                        </span>
                        <span
                            v-if="item.my_verdict"
                            class="text-xs text-muted-foreground"
                        >
                            ·
                            {{
                                t('You said: :verdict', {
                                    verdict: verdictLabel(item.my_verdict),
                                })
                            }}
                        </span>
                    </div>
                </li>
            </ol>
        </CardContent>
    </Card>
</template>
