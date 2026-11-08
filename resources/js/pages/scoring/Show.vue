<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    Building2,
    RefreshCw,
    Sparkles,
    Target,
    ThumbsDown,
    ThumbsUp,
} from '@lucide/vue';
import { computed } from 'vue';
import { ListCell, ListHead, ListRow, ListTable } from '@/components/list';
import MeterBar from '@/components/MeterBar.vue';
import PageHero from '@/components/PageHero.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import ScoreDial from '@/components/scoring/ScoreDial.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    CriterionMatch,
    Lead,
    LeadScore,
    ScoreBreakdownRow,
    ScoringActiveIcp,
} from '@/types';

/*
 * One lead's fit score, and why.
 *
 * What the rebuild changed:
 *
 *  - **The score is a dial.** "68" in a grey square asks the reader to remember the scale and
 *    guess whether that is most of the way or barely half; an arc answers both before the number
 *    is read, and it is coloured by grade with the same tokens `GradeBadge` uses.
 *  - **The breakdown shows what each criterion was worth.** The server has sent `contribution` —
 *    the points a row actually added — since Phase 9, and this screen quietly dropped it, printing
 *    a bare "Weight 70" instead. A member looking at a C wants to know *which* criteria cost it
 *    the points, and that is the column that answers it. The rows are ordered heaviest first for
 *    the same reason.
 *  - **The timestamp is in the team's timezone.** It was `new Date(...).toLocaleString()`, which
 *    formats in the browser's zone and in the browser's locale — so the same score read
 *    differently depending on the laptop, and in English inside an Arabic page. Everything
 *    user-facing goes through `useFormat` (CLAUDE.md §2).
 *  - **There is a way to the lead.** The only way out of this screen was back to discovery, which
 *    is not where somebody who has just read a grade wants to go.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Lead discovery', href: '/discovery' },
            { title: 'Lead score', href: '/discovery' },
        ],
    },
});

const props = defineProps<{
    lead: Lead;
    score: LeadScore | null;
    activeIcp: ScoringActiveIcp | null;
    can: { rescore: boolean; giveFeedback: boolean };
}>();

const { t } = useI18n();
const { dateTime, number } = useFormat();

const scoredAt = computed(() =>
    props.score?.scored_at ? dateTime(props.score.scored_at) : null,
);

// The breakdown splits three ways, because the rules give three kinds of answer. Only a *confident*
// match or miss belongs in the scored table; the rest are set apart so neither reads as a mark
// against the lead:
//  - `assessed` — matched or confidently missed, ordered by weight because "what cost this lead its
//    grade" is a question about the heavy criteria;
//  - `deferred` — the lead had data the rules couldn't confirm from its wording, so the AI review
//    judged it (CriterionMatch::Weak). It counted towards neither side of the rule score;
//  - `unassessed` — the lead has no data for it at all (a coverage gap, never a failure).
const assessed = computed<ScoreBreakdownRow[]>(() =>
    (props.score?.breakdown ?? [])
        .filter((row) => row.match === 'matched' || row.match === 'not_matched')
        .slice()
        .sort((a, b) => b.weight - a.weight),
);

const deferred = computed(
    () => props.score?.breakdown.filter((row) => row.match === 'weak') ?? [],
);

const unassessed = computed(
    () => props.score?.breakdown.filter((row) => row.match === 'unknown') ?? [],
);

/** Every bar in the table is a fraction of the same thing: the profile's heaviest criterion. */
const topWeight = computed(() =>
    Math.max(1, ...assessed.value.map((row) => row.weight)),
);

const matchVariant: Record<CriterionMatch, 'success' | 'outline'> = {
    matched: 'success',
    not_matched: 'outline',
    weak: 'outline',
    unknown: 'outline',
};

const myRating = computed(() => props.score?.my_feedback?.rating ?? null);

// The screen captures the verdict only; the endpoint also accepts an optional note, which the
// mobile app and a later "tell us why" affordance can send.
const feedbackForm = useForm({ rating: 'good' });

function rescore(): void {
    router.post(`/leads/${props.lead.id}/score`, {}, { preserveScroll: true });
}

function sendFeedback(rating: 'good' | 'bad'): void {
    feedbackForm
        .transform(() => ({ rating }))
        .post(`/leads/${props.lead.id}/score/feedback`, {
            preserveScroll: true,
        });
}
</script>

<template>
    <Head :title="t('Lead score')" />

    <div class="flex flex-col gap-6 py-2">
        <!-- Header. The company name is the Lime tail — data, not copy, so it goes through
             `accent` rather than a `|` in the statement (`PageHero`). -->
        <PageHero
            index="06"
            :eyebrow="t('Lead score')"
            :title="t('The grade for')"
            :accent="lead.company_name"
            :description="
                activeIcp
                    ? t('Graded against your “:icp” profile', {
                          icp: activeIcp.name,
                      })
                    : null
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="can.rescore && activeIcp"
                    variant="lime"
                    data-test="rescore"
                    @click="rescore"
                >
                    <RefreshCw class="size-4" />
                    {{ score ? t('Grade again') : t('Grade this lead') }}
                </Button>
                <!-- Where somebody who has just read a grade actually wants to go. -->
                <Button variant="hero" as-child>
                    <Link :href="`/leads/${lead.id}`">
                        <Building2 class="size-4" />
                        {{ t('Open the lead') }}
                    </Link>
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/discovery">
                        <ArrowLeft class="size-4 rtl:rotate-180" />
                        {{ t('Back to discovery') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <!-- No active ICP: nothing to grade against -->
        <EmptyState
            v-if="!activeIcp"
            :icon="Target"
            :title="t('No active customer profile')"
            :description="
                t(
                    'Alima grades a lead against your ideal customer profile. Set one as active and your leads get a grade, a breakdown and an explanation.',
                )
            "
            data-test="no-icp"
        >
            <Button variant="brand" as-child>
                <Link href="/icp">
                    <Target class="size-4" />
                    {{ t('Set up your profile') }}
                </Link>
            </Button>
        </EmptyState>

        <!-- Not graded yet -->
        <EmptyState
            v-else-if="!score"
            :icon="Sparkles"
            :title="t('Not graded yet')"
            :description="
                t(
                    'Alima grades a lead once it has been enriched. You can also grade it now.',
                )
            "
            data-test="not-scored"
        >
            <Button
                v-if="can.rescore"
                variant="brand"
                data-test="score-now"
                @click="rescore"
            >
                <Sparkles class="size-4" />
                {{ t('Grade this lead') }}
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Ask an owner or admin to grade it.') }}
            </p>
        </EmptyState>

        <template v-else>
            <!-- Grade panel -->
            <section
                class="flex flex-col gap-6 rounded-2xl border border-border bg-card p-6 sm:flex-row sm:items-center sm:justify-between"
                data-test="grade-panel"
            >
                <div class="flex items-center gap-5">
                    <ScoreDial
                        :score="score.score"
                        :grade="score.grade"
                        :grade-label="t(score.grade_label)"
                        size="lg"
                    />
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2">
                            <GradeBadge
                                :grade="score.grade"
                                :label="t(score.grade_label)"
                            />
                            <span
                                class="text-base font-semibold text-foreground rtl:font-bold"
                            >
                                {{ t(score.grade_label) }}
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ t(score.grade_description) }}
                        </p>
                    </div>
                </div>

                <!-- How the blend came out. Both halves are out of 100, and the em dash is the
                     honest answer where the AI half never ran (brand-tokens.md §7g.3). -->
                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm sm:max-w-xs"
                >
                    <dt class="text-muted-foreground">
                        {{ t('From your criteria') }}
                    </dt>
                    <dd class="flex items-center justify-end gap-3">
                        <MeterBar
                            :value="score.deterministic_score"
                            width="sm"
                            :label="
                                t(':score out of 100', {
                                    score: score.deterministic_score,
                                })
                            "
                        />
                        <span
                            class="w-7 text-end font-mono text-foreground tabular-nums"
                            aria-hidden="true"
                            dir="ltr"
                            >{{ score.deterministic_score }}</span
                        >
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('From the AI review') }}
                    </dt>
                    <dd class="flex items-center justify-end gap-3">
                        <MeterBar
                            v-if="score.ai_score !== null"
                            :value="score.ai_score"
                            width="sm"
                            :label="
                                t(':score out of 100', {
                                    score: score.ai_score,
                                })
                            "
                        />
                        <span
                            class="w-7 text-end font-mono text-foreground tabular-nums"
                            :aria-hidden="
                                score.ai_score !== null ? 'true' : undefined
                            "
                            dir="ltr"
                            >{{ score.ai_score ?? '—' }}</span
                        >
                    </dd>
                    <template v-if="scoredAt">
                        <dt class="text-muted-foreground">
                            {{ t('Graded') }}
                        </dt>
                        <dd class="text-end text-muted-foreground" dir="auto">
                            {{ scoredAt }}
                        </dd>
                    </template>
                </dl>
            </section>

            <!-- Excluded by the profile -->
            <section
                v-if="score.excluded"
                class="flex items-start gap-3 rounded-2xl border border-border bg-card p-6"
                role="status"
                data-test="excluded"
            >
                <!-- Neutral, not an error: being outside the profile is a decision the team made,
                     exactly as a grade D is neutral rather than destructive. -->
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent text-muted-foreground"
                >
                    <Ban class="size-5" />
                </span>
                <div class="space-y-1">
                    <h2 class="font-semibold text-foreground rtl:font-bold">
                        {{ t('Ruled out by your profile') }}
                    </h2>
                    <p class="text-sm text-muted-foreground" dir="auto">
                        {{
                            t(
                                'Your profile excludes “:value”, and this lead matches it. Alima stopped there and didn’t run the AI review.',
                                { value: score.excluded_by ?? '' },
                            )
                        }}
                    </p>
                </div>
            </section>

            <!-- Explanation -->
            <section
                v-if="score.explanation"
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
                data-test="explanation"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Why this lead matches') }}
                </h2>
                <p class="leading-relaxed text-foreground" dir="auto">
                    {{ score.explanation }}
                </p>

                <div
                    v-if="score.strengths.length || score.concerns.length"
                    class="grid gap-5 border-t border-border pt-4 sm:grid-cols-2"
                >
                    <div v-if="score.strengths.length" class="space-y-2">
                        <h3
                            class="text-sm font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('In its favour') }}
                        </h3>
                        <ul class="space-y-1.5">
                            <li
                                v-for="item in score.strengths"
                                :key="item"
                                class="flex items-start gap-2 text-sm text-muted-foreground"
                                dir="auto"
                            >
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-success"
                                    aria-hidden="true"
                                />
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                    <div v-if="score.concerns.length" class="space-y-2">
                        <h3
                            class="text-sm font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('Worth checking') }}
                        </h3>
                        <ul class="space-y-1.5">
                            <li
                                v-for="item in score.concerns"
                                :key="item"
                                class="flex items-start gap-2 text-sm text-muted-foreground"
                                dir="auto"
                            >
                                <span
                                    class="mt-1.5 size-1.5 shrink-0 rounded-full bg-warning"
                                    aria-hidden="true"
                                />
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Breakdown -->
            <section class="space-y-3" data-test="breakdown">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Criterion by criterion') }}
                </h2>

                <ListTable
                    v-if="assessed.length"
                    :caption="
                        t('How each of your criteria scored against this lead')
                    "
                >
                    <thead>
                        <tr>
                            <ListHead :label="t('Criterion')" />
                            <ListHead
                                :label="t('Found on the lead')"
                                hide="md"
                            />
                            <ListHead :label="t('Importance')" align="end" />
                            <ListHead
                                :label="t('Points')"
                                align="end"
                                hide="sm"
                            />
                            <ListHead :label="t('Result')" align="end" />
                        </tr>
                    </thead>
                    <tbody>
                        <ListRow
                            v-for="(row, index) in assessed"
                            :key="`${row.type}-${index}`"
                        >
                            <ListCell>
                                <span
                                    class="font-medium text-foreground"
                                    dir="auto"
                                    >{{ row.value }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ t(row.type_label) }}</span
                                >
                            </ListCell>
                            <!-- On an assessed row the evidence is the lead's own value, so it is
                                 shown verbatim, never translated. Unassessed rows carry one of the
                                 scorer's fixed notes, which is. -->
                            <ListCell hide="md" muted>
                                <span dir="auto">{{
                                    row.evidence ?? '—'
                                }}</span>
                            </ListCell>
                            <ListCell align="end">
                                <span class="inline-flex items-center gap-3">
                                    <MeterBar
                                        :value="row.weight"
                                        :max="topWeight"
                                        width="sm"
                                        :tone="
                                            row.match === 'matched'
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            t('Importance :weight out of 100', {
                                                weight: row.weight,
                                            })
                                        "
                                    />
                                    <span
                                        class="w-6 text-end font-mono text-xs text-muted-foreground tabular-nums"
                                        aria-hidden="true"
                                        dir="ltr"
                                        >{{ row.weight }}</span
                                    >
                                </span>
                            </ListCell>
                            <!-- What this criterion was actually worth. Zero reads as a dash: it
                                 contributed nothing, which is not the same as scoring nought. -->
                            <ListCell hide="sm" align="end" mono>
                                <span
                                    :class="
                                        row.contribution > 0
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                    dir="ltr"
                                >
                                    {{
                                        row.contribution > 0
                                            ? `+${number(row.contribution)}`
                                            : '—'
                                    }}
                                </span>
                            </ListCell>
                            <ListCell align="end">
                                <Badge :variant="matchVariant[row.match]">
                                    {{ t(row.match_label) }}
                                </Badge>
                            </ListCell>
                        </ListRow>
                    </tbody>
                </ListTable>

                <!-- Criteria the lead had data for but the rules couldn't confirm from its wording,
                     so the AI review judged them. Neutral: they counted towards neither side. -->
                <div
                    v-if="deferred.length"
                    class="space-y-2 rounded-2xl border border-dashed border-border bg-card/50 p-5"
                    data-test="deferred"
                >
                    <h3
                        class="text-sm font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Left to the AI review') }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'The lead describes these differently than your profile, so the rules couldn’t confirm them for sure. The AI review weighed them instead — they didn’t count for or against the rule score.',
                            )
                        }}
                    </p>
                    <ul class="space-y-1.5 pt-1">
                        <li
                            v-for="(row, index) in deferred"
                            :key="`${row.type}-${index}`"
                            class="text-sm text-muted-foreground"
                            dir="auto"
                        >
                            <span class="text-foreground">{{ row.value }}</span>
                            <span class="text-xs">
                                · {{ t(row.type_label) }}
                                <template v-if="row.evidence">
                                    · {{ t('lead has') }}
                                    <span class="text-foreground">{{
                                        row.evidence
                                    }}</span>
                                </template>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Criteria we couldn't judge: a data gap, never a mark against the lead -->
                <div
                    v-if="unassessed.length"
                    class="space-y-2 rounded-2xl border border-dashed border-border bg-card/50 p-5"
                    data-test="unassessed"
                >
                    <h3
                        class="text-sm font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Not enough data to judge') }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'These criteria didn’t count either way, so a thin lead is never marked down for what Alima simply doesn’t know yet.',
                            )
                        }}
                    </p>
                    <ul class="space-y-1.5 pt-1">
                        <li
                            v-for="(row, index) in unassessed"
                            :key="`${row.type}-${index}`"
                            class="text-sm text-muted-foreground"
                            dir="auto"
                        >
                            <span class="text-foreground">{{ row.value }}</span>
                            <span class="text-xs">
                                · {{ t(row.type_label) }}
                                <template v-if="row.evidence">
                                    · {{ t(row.evidence) }}
                                </template>
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Feedback -->
            <section
                v-if="can.giveFeedback"
                class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-6 sm:flex-row sm:items-center sm:justify-between"
                data-test="feedback"
            >
                <div class="space-y-1">
                    <h2 class="font-semibold text-foreground rtl:font-bold">
                        {{ t('Did Alima get this right?') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            myRating
                                ? t('Thanks — that helps Alima grade better.')
                                : t(
                                      'Tell us and Alima gets better at grading your leads.',
                                  )
                        }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        :variant="myRating === 'good' ? 'brand' : 'outline'"
                        :disabled="feedbackForm.processing"
                        :aria-pressed="myRating === 'good'"
                        data-test="feedback-good"
                        @click="sendFeedback('good')"
                    >
                        <ThumbsUp class="size-4" />
                        {{ t('Looks right') }}
                    </Button>
                    <Button
                        :variant="myRating === 'bad' ? 'brand' : 'outline'"
                        :disabled="feedbackForm.processing"
                        :aria-pressed="myRating === 'bad'"
                        data-test="feedback-bad"
                        @click="sendFeedback('bad')"
                    >
                        <ThumbsDown class="size-4" />
                        {{ t('Looks wrong') }}
                    </Button>
                </div>
            </section>
        </template>
    </div>
</template>
