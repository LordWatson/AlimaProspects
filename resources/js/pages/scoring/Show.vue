<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    RefreshCw,
    Sparkles,
    Target,
    ThumbsDown,
    ThumbsUp,
} from '@lucide/vue';
import { computed } from 'vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useI18n } from '@/composables/useI18n';
import type {
    CriterionMatch,
    Lead,
    LeadScore,
    ScoringActiveIcp,
} from '@/types';

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

const scoredAt = computed(() =>
    props.score?.scored_at
        ? new Date(props.score.scored_at).toLocaleString()
        : null,
);

// Criteria the lead has no data for are listed apart: they are a data-coverage gap, not a failure,
// and they never counted towards the score.
const assessed = computed(
    () => props.score?.breakdown.filter((row) => row.match !== 'unknown') ?? [],
);
const unassessed = computed(
    () => props.score?.breakdown.filter((row) => row.match === 'unknown') ?? [],
);

const matchVariant: Record<CriterionMatch, 'success' | 'outline'> = {
    matched: 'success',
    not_matched: 'outline',
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

    <div class="flex flex-col gap-8 py-2">
        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="max-w-2xl space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Lead score') }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                    dir="auto"
                >
                    {{ lead.company_name }}<span class="text-orange">.</span>
                </h1>
                <p v-if="activeIcp" class="text-base text-muted-foreground">
                    {{
                        t('Graded against your “:icp” profile', {
                            icp: activeIcp.name,
                        })
                    }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="can.rescore && activeIcp"
                    variant="outline"
                    data-test="rescore"
                    @click="rescore"
                >
                    <RefreshCw class="size-4" />
                    {{ score ? t('Grade again') : t('Grade this lead') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/discovery">
                        <ArrowLeft class="size-4" />
                        {{ t('Back to discovery') }}
                    </Link>
                </Button>
            </div>
        </header>

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
                    <p
                        class="flex size-20 shrink-0 items-center justify-center rounded-2xl bg-accent font-mono text-4xl font-semibold text-foreground tabular-nums"
                        dir="ltr"
                    >
                        <!-- Spoken in full, so the number is never read without its scale. -->
                        <span class="sr-only">{{
                            t('Fit score :score out of 100', {
                                score: score.score,
                            })
                        }}</span>
                        <span aria-hidden="true">{{ score.score }}</span>
                    </p>
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

                <dl
                    class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:text-end"
                >
                    <dt class="text-muted-foreground">
                        {{ t('From your criteria') }}
                    </dt>
                    <dd
                        class="font-mono text-foreground tabular-nums"
                        dir="ltr"
                    >
                        {{ score.deterministic_score }}
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('From the AI review') }}
                    </dt>
                    <dd
                        class="font-mono text-foreground tabular-nums"
                        dir="ltr"
                    >
                        {{ score.ai_score ?? '—' }}
                    </dd>
                    <dt v-if="scoredAt" class="text-muted-foreground">
                        {{ t('Graded') }}
                    </dt>
                    <dd
                        v-if="scoredAt"
                        class="text-muted-foreground"
                        dir="auto"
                    >
                        {{ scoredAt }}
                    </dd>
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

                <div
                    v-if="assessed.length"
                    class="overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t(
                                    'How each of your criteria scored against this lead',
                                )
                            }}
                        </caption>
                        <thead class="bg-accent/50 text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-start font-medium"
                                >
                                    {{ t('Criterion') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium md:table-cell"
                                >
                                    {{ t('Found on the lead') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-end font-medium"
                                >
                                    {{ t('Weight') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-end font-medium"
                                >
                                    {{ t('Result') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in assessed"
                                :key="`${row.type}-${index}`"
                                class="border-t border-border"
                            >
                                <td class="px-4 py-3">
                                    <span
                                        class="font-medium text-foreground"
                                        dir="auto"
                                        >{{ row.value }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ t(row.type_label) }}</span
                                    >
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                                    dir="auto"
                                >
                                    <!-- On an assessed row the evidence is the lead's own value,
                                         so it is shown verbatim, never translated. Unassessed rows
                                         carry one of the scorer's fixed notes, which is. -->
                                    {{ row.evidence ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-end font-mono text-xs text-muted-foreground tabular-nums"
                                    dir="ltr"
                                >
                                    {{ row.weight }}
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <Badge :variant="matchVariant[row.match]">
                                        {{ t(row.match_label) }}
                                    </Badge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
