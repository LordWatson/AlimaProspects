<script setup lang="ts">
import { computed } from 'vue';
import GradeMixBar from '@/components/charts/GradeMixBar.vue';
import MeterBar from '@/components/MeterBar.vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    BreakdownSlice,
    CriterionCalibration,
    IcpCalibration,
} from '@/types';

/*
 * How the active profile is *actually* grading the team's leads — the piece that turns an ICP from
 * a fire-and-forget definition into something a team can steer. If most leads are landing C/D, the
 * per-criterion bars say which criterion is doing the rejecting, so the fix ("loosen this", "this is
 * really a different sector") is a decision rather than a guess.
 *
 * Read-only and free: it reads scores already stored, never re-grades. "Unconfirmed" is kept
 * visually apart from "No match" on purpose — a criterion the AI review judged is not one the lead
 * failed, and a team loosening the wrong criterion is the mistake this panel exists to prevent.
 */
const props = defineProps<{ calibration: IcpCalibration; icpName: string }>();

const { t } = useI18n();
const { number, percent } = useFormat();

const gradeSlices = computed<BreakdownSlice[]>(() =>
    (['A', 'B', 'C', 'D'] as const).map((letter) => ({
        key: letter,
        label: labelFor(letter),
        value: props.calibration.grades[letter] ?? 0,
        hint: null,
    })),
);

function labelFor(letter: 'A' | 'B' | 'C' | 'D'): string {
    return {
        A: 'A — strong fit',
        B: 'B — good fit',
        C: 'C — partial fit',
        D: 'D — weak fit',
    }[letter];
}

/** The share of assessed leads this criterion matched — the one number that ranks "worth tuning". */
function matchedShare(row: CriterionCalibration): number {
    return row.total === 0 ? 0 : row.matched / row.total;
}

const segments: Array<{
    key: keyof Pick<
        CriterionCalibration,
        'matched' | 'not_matched' | 'weak' | 'unknown'
    >;
    label: string;
    dot: string;
}> = [
    { key: 'matched', label: 'matched', dot: 'bg-success' },
    { key: 'not_matched', label: 'no match', dot: 'bg-ink-60' },
    { key: 'weak', label: 'unconfirmed', dot: 'bg-info' },
    { key: 'unknown', label: 'no data', dot: 'bg-ink-40' },
];
</script>

<template>
    <section
        class="space-y-6 rounded-2xl border border-border bg-card p-6"
        data-test="calibration"
    >
        <div class="space-y-1">
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('Calibration') }}
            </p>
            <h2
                class="text-xl font-semibold tracking-tight text-foreground rtl:font-bold"
            >
                {{ t('How your active profile is grading') }}
            </h2>
            <p class="text-sm text-muted-foreground" dir="auto">
                {{
                    calibration.scored_leads === 0
                        ? t('Nothing has been graded against “:name” yet.', {
                              name: icpName,
                          })
                        : t(':count leads graded against “:name”.', {
                              count: number(calibration.scored_leads),
                              name: icpName,
                          })
                }}
            </p>
        </div>

        <template v-if="calibration.scored_leads > 0">
            <GradeMixBar :slices="gradeSlices" />

            <p
                v-if="calibration.excluded > 0"
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        ':count of them were ruled out by an exclusion before grading.',
                        { count: number(calibration.excluded) },
                    )
                }}
            </p>

            <div
                v-if="calibration.criteria.length"
                class="space-y-4 border-t border-border pt-5"
            >
                <div class="space-y-1">
                    <h3
                        class="text-sm font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Which criteria are matching') }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'If leads keep landing C or D, the criteria they rarely match are the ones to loosen or re-word. “Unconfirmed” means the AI review judged it — not a miss.',
                            )
                        }}
                    </p>
                </div>

                <ul class="space-y-4">
                    <li
                        v-for="(row, index) in calibration.criteria"
                        :key="`${row.type}-${index}`"
                        class="space-y-2"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 text-sm">
                                <span class="text-foreground" dir="auto">{{
                                    row.value
                                }}</span>
                                <span class="text-muted-foreground">
                                    · {{ t(row.type_label) }}</span
                                >
                            </span>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                dir="ltr"
                            >
                                {{ percent(matchedShare(row)) }}
                                {{ t('match') }}
                            </span>
                        </div>

                        <MeterBar
                            :value="row.matched"
                            :max="Math.max(1, row.total)"
                            width="full"
                            tone="success"
                            :label="
                                t(':matched of :total leads matched this', {
                                    matched: number(row.matched),
                                    total: number(row.total),
                                })
                            "
                        />

                        <!-- The split behind the bar, so a low match rate can be read as "missing"
                             (loosen it), "unconfirmed" (the AI judged it) or "no data" (enrich). -->
                        <ul
                            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="segment in segments"
                                :key="segment.key"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="segment.dot"
                                    aria-hidden="true"
                                />
                                <span class="tabular-nums"
                                    >{{ number(row[segment.key]) }}
                                    {{ t(segment.label) }}</span
                                >
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
