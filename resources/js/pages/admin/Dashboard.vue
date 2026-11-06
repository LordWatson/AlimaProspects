<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CircleCheck,
    OctagonAlert,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminSeriesChart from '@/components/admin/AdminSeriesChart.vue';
import { StatTile } from '@/components/charts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useAdminNav } from '@/composables/useAdminNav';
import { useFormat } from '@/composables/useFormat';
import type { Kpi } from '@/types';

/*
 * The panel's front page (Phase 19a; rebuilt in the 19d overhaul).
 *
 * **What it answers, in the order somebody asks it:** is anything on fire, is the business growing,
 * how big is the platform, and is anybody suspended. The old version answered only the third — a
 * grid of eight live counts — which is why it read as a wall of numbers with no story: counts tell
 * you the size of a thing and nothing about its direction.
 *
 * So the page now leads with **health**, because a figure from a rollup that did not run is worse
 * than no figure; then five **trended** tiles and a chart over the same thirty days, from the same
 * nightly `platform_daily_metrics` rollup that platform reporting reads; then the live counts,
 * demoted to a mono strip where they belong; then the suspended tenants.
 *
 * It is still not the reporting screen (ADR 0016): no window picker, no cohort rules. The chart's
 * switcher is **client-side** over series that came down with the page, so toggling costs nothing.
 */
const props = defineProps<{
    stats: {
        teams: number;
        teams_suspended: number;
        users: number;
        operators: number;
        leads: number;
        discovery_runs_24h: number;
        ai_requests_24h: number;
        ai_cost_micros_24h: number;
    };
    suspended: {
        id: number;
        name: string;
        suspended_at: string | null;
        reason: string | null;
    }[];
    /** The trended headlines, in `StatTile`'s own shape. */
    tiles: Kpi[];
    /** The series the chart offers, switched client-side. */
    series: {
        key: string;
        label: string;
        caption: string;
        format: 'count' | 'money';
        points: { label: string; value: number }[];
    }[];
    health: {
        ok: boolean;
        failed_jobs: number;
        breakers_open: string[];
        rollup_day: string | null;
        rollup_stale: boolean;
    };
}>();

const { number, utcDateTime } = useFormat();
const { base } = useAdminNav();

// --- The chart's switcher ----------------------------------------------------------------------
const chosen = ref(props.series[0]?.key ?? '');

const shown = computed(
    () => props.series.find((series) => series.key === chosen.value) ?? null,
);

// --- Health ------------------------------------------------------------------------------------
/**
 * One line, and the icon that matches it.
 *
 * Three levels, mapped to `status.*` as §7f sets out, with the words in `text-foreground` and the
 * colour kept to the icon and the border — a status colour over a tint of itself does not clear AA
 * at this size. Each level has its own **icon and its own word**, because warning and critical
 * otherwise differ only by hue (WCAG 1.4.1).
 */
const verdict = computed(() => {
    if (props.health.rollup_stale) {
        return {
            level: 'Critical',
            icon: OctagonAlert,
            tone: 'border-destructive/30 bg-destructive/12',
            mark: 'text-destructive',
            line:
                props.health.rollup_day === null
                    ? 'The nightly rollup has never run here, so every figure below is empty rather than zero.'
                    : `The nightly rollup last ran for ${props.health.rollup_day}. Every figure below is older than it looks.`,
            href: `${base.value}/operations`,
        };
    }

    if (props.health.failed_jobs > 0 || props.health.breakers_open.length > 0) {
        const parts: string[] = [];

        if (props.health.failed_jobs > 0) {
            // Singular when there is one. "1 failed jobs" is the sort of thing that makes a tool
            // feel unfinished (brand review).
            parts.push(
                props.health.failed_jobs === 1
                    ? '1 job has failed'
                    : `${number(props.health.failed_jobs)} jobs have failed`,
            );
        }

        if (props.health.breakers_open.length > 0) {
            // "we have stopped calling", not "circuit-broken": the voice rule is plain words, and
            // the operator needs to know the consequence rather than the mechanism.
            parts.push(
                `we have stopped calling ${props.health.breakers_open.join(', ')}`,
            );
        }

        return {
            level: 'Warning',
            icon: TriangleAlert,
            tone: 'border-warning/30 bg-warning/12',
            mark: 'text-warning',
            // Hedged on purpose: one open breaker on one provider is not every tenant losing
            // work, and a panel that overstates is a panel somebody learns to discount.
            line: `${parts.join(' · ')}. Some tenants' work is stuck until this clears.`,
            href: `${base.value}/operations`,
        };
    }

    return {
        level: 'All clear',
        icon: CircleCheck,
        // `status.success` on its own 12% tint, like the other two levels — the accent is for the
        // brand's own emphasis, and spending it on a status was a quiet fourth use of teal that
        // §7k.2 did not list (brand review).
        tone: 'border-success/30 bg-success/12',
        mark: 'text-success',
        line: `Queues are clear, every provider is answering, and the rollup is current to ${props.health.rollup_day ?? 'today'}.`,
        href: null,
    };
});

/*
 * The live counts, demoted.
 *
 * They are still worth having — "how big is this thing" is a real question — but they are facts
 * without direction, so they read as a mono strip rather than as eight cards competing with the
 * trends above. The AI cost is in **cost micro-units**, not money, and the label says so rather
 * than the number pretending: a cross-tenant sum is what *we* paid providers, not what any tenant
 * is billed in.
 */
const counts = computed(() => [
    { label: 'Tenants', value: number(props.stats.teams) },
    { label: 'Suspended', value: number(props.stats.teams_suspended) },
    { label: 'Members', value: number(props.stats.users) },
    { label: 'Operators', value: number(props.stats.operators) },
    { label: 'Leads', value: number(props.stats.leads) },
    { label: 'Discoveries 24h', value: number(props.stats.discovery_runs_24h) },
    { label: 'AI calls 24h', value: number(props.stats.ai_requests_24h) },
    {
        // Said in words rather than in our unit name: "micros" is the ledger's jargon, and the
        // reason it is not money is worth a hint rather than a glossary (brand review).
        label: 'AI spend 24h (micros)',
        value: number(props.stats.ai_cost_micros_24h),
    },
]);
</script>

<template>
    <Head title="Operations" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform"
            title="Everything, |across every tenant."
            lede="Reads of tenant data are audited. Figures come from last night's rollup; the strip at the bottom is live."
        >
            <template #actions>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="`${base}/reporting`">
                        Full reporting
                        <ArrowUpRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </template>
        </AdminPageHeader>

        <!-- Is anything on fire? First, because it decides whether to trust the rest. -->
        <section
            class="flex flex-wrap items-center gap-3 rounded-2xl border p-4"
            :class="verdict.tone"
            :aria-label="`Platform health: ${verdict.level}`"
            data-test="platform-health"
        >
            <component
                :is="verdict.icon"
                class="size-5 shrink-0"
                :class="verdict.mark"
                aria-hidden="true"
            />
            <p class="min-w-0 flex-1 text-sm text-foreground">
                <span class="sr-only">{{ verdict.level }}: </span>
                {{ verdict.line }}
            </p>
            <Button v-if="verdict.href" variant="outline" size="sm" as-child>
                <Link :href="verdict.href">Look into it</Link>
            </Button>
        </section>

        <!-- The business, trended. -->
        <section class="space-y-4" aria-label="The last thirty days">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    The last 30 days
                </h2>
                <p class="font-mono text-xs text-muted-foreground tabular-nums">
                    rolled up to {{ health.rollup_day ?? 'never' }}
                </p>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <!-- `chart-2`, the Teal single-series token: the panel has one accent, and
                     `chart-1` is Blue (brand review). -->
                <StatTile
                    v-for="tile in tiles"
                    :key="tile.key"
                    :kpi="tile"
                    tone="text-chart-2"
                />
            </dl>
        </section>

        <!-- One series at a time, switched without a round trip. -->
        <section
            v-if="series.length > 0"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
            aria-label="Daily trend"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold text-foreground">
                        {{ shown?.label }}
                    </h2>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        {{ shown?.caption }}
                    </p>
                </div>

                <!-- Buttons rather than a select: three options, and the point is to flick
                     between them. The pressed state carries a weight change as well as the
                     fill, so it is not colour alone. -->
                <div
                    class="flex flex-wrap gap-1 rounded-full border border-border p-1"
                    role="group"
                    aria-label="Which series"
                >
                    <button
                        v-for="option in series"
                        :key="option.key"
                        type="button"
                        class="duration-fast rounded-full px-3 py-1 text-sm transition-colors ease-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            option.key === chosen
                                ? 'bg-teal font-semibold text-cream'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-pressed="option.key === chosen"
                        :data-test="`series-${option.key}`"
                        @click="chosen = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <AdminSeriesChart
                v-if="shown"
                :points="shown.points"
                :title="shown.label"
                :format="shown.format"
            />
        </section>

        <!-- How big the thing is, live. A strip, not a grid of cards: these are facts without a
             direction, and they were the whole page once. -->
        <section aria-label="Live counts">
            <h2 class="sr-only">Live counts</h2>
            <dl
                class="flex flex-wrap gap-x-8 gap-y-3 rounded-2xl border border-border bg-card px-6 py-4"
            >
                <div
                    v-for="count in counts"
                    :key="count.label"
                    class="min-w-24"
                >
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        {{ count.label }}
                    </dt>
                    <dd
                        class="mt-0.5 text-lg font-semibold text-foreground tabular-nums"
                    >
                        {{ count.value }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="flex flex-col gap-3" aria-label="Suspended tenants">
            <h2
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Suspended tenants
            </h2>

            <EmptyState
                v-if="suspended.length === 0"
                title="Nobody is suspended"
                description="A suspended tenant keeps reading its own data but cannot source, send or spend."
            />

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="team in suspended"
                    :key="team.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-border bg-card px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">
                            {{ team.name }}
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ team.reason ?? 'No reason recorded.' }}
                        </p>
                    </div>

                    <Badge variant="warning">
                        {{
                            team.suspended_at
                                ? utcDateTime(team.suspended_at)
                                : 'Suspended'
                        }}
                    </Badge>

                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`${base}/resources/teams/${team.id}`">
                            Open
                        </Link>
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
