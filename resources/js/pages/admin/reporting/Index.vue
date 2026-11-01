<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Info, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminWindowFilter from '@/components/admin/AdminWindowFilter.vue';
import {
    BarListChart,
    FunnelChart,
    ReportTableView,
    StatTile,
} from '@/components/charts';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import type {
    AdminWindowFilters,
    PlatformReportKey,
    PlatformReportPage,
} from '@/types';

/*
 * Platform reporting (Phase 19c) — **is this business working, and is this customer profitable**.
 *
 * Three things on this page are there to stop it lying, and all three are the phase's own risk
 * notes made visible:
 *
 *  1. **"Figures as at"** is in the header, not a footnote. Every number comes off the nightly
 *     rollup, so a command that failed on Tuesday makes Wednesday look like a quiet day. The date
 *     is the first thing an operator reads.
 *  2. **Every figure's definition is rendered next to it.** A platform aggregate that quietly
 *     answers a tenant-shaped question has no customer to complain, so each table carries the
 *     counting rules the server wrote for its measures.
 *  3. **Unconverted cost is announced.** Gross margin crosses a currency boundary on a configured
 *     rate; a provider currency nobody set a rate for is excluded and said so, which makes the
 *     margins on the page a ceiling rather than a figure.
 *
 * Brand (§2a): the panel's existing Deep Ink shell, the validated `--chart-*` tokens through the
 * shared chart set, DM Mono eyebrows. No Signal Orange anywhere — this screen is nothing but
 * states. English only, like the rest of the panel.
 */
const props = defineProps<{
    report: PlatformReportPage;
    filters: AdminWindowFilters;
    ranges: { value: string; label: string }[];
    reports: { value: PlatformReportKey; label: string; caption: string }[];
}>();

const { number, currency, utcDate } = useFormat();

const activeTab = ref<PlatformReportKey>('margin');

const activeTable = computed(
    () =>
        props.report.tables.find((table) => table.key === activeTab.value) ??
        props.report.tables[0],
);

/** The export link carries the window being shown, so a download matches the screen. */
const exportHref = computed(() => {
    const params = new URLSearchParams({
        report: activeTab.value,
        range: props.filters.range,
    });

    if (props.filters.from !== null && props.filters.to !== null) {
        params.set('from', props.filters.from);
        params.set('to', props.filters.to);
    }

    return `/admin/reporting/export?${params.toString()}`;
});

const fxNote = computed(() =>
    Object.entries(props.report.fx_rates)
        .map(
            ([code, rate]) =>
                `1 ${code} = ${currency(rate, props.report.currency)}`,
        )
        .join(' · '),
);

const cohortCount = computed(() => props.report.funnel[0]?.value ?? 0);
</script>

<template>
    <Head title="Platform reporting" />

    <div class="flex flex-col gap-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Platform
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    Reporting
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    The business's own numbers. Every figure is an aggregate and
                    names nobody, so this screen is not audited as a privacy
                    event — the exports are.
                </p>
            </div>

            <!--
              Freshness, in the header. Not decoration: the figures are only as current as the
              rollup, and a nightly command that failed must not look like a quiet week.
            -->
            <p class="text-sm text-muted-foreground">
                <span
                    class="block font-mono text-xs tracking-eyebrow uppercase"
                >
                    Figures as at
                </span>
                <span
                    v-if="report.last_rolled_up"
                    class="text-foreground tabular-nums"
                >
                    {{ utcDate(report.last_rolled_up) }} (UTC)
                </span>
                <span v-else class="text-destructive">
                    never rolled up — run <code>platform:roll-up</code>
                </span>
            </p>
        </header>

        <AdminWindowFilter :filters="filters" :ranges="ranges" />

        <div
            v-if="report.has_unconverted_cost"
            class="flex items-start gap-3 rounded-2xl border border-warning/30 bg-warning/12 px-4 py-3"
        >
            <TriangleAlert class="mt-0.5 size-5 shrink-0 text-warning" />
            <p class="text-sm text-foreground">
                Some provider cost is in a currency with no configured exchange
                rate, so it is excluded from the totals below.
                <strong
                    >Every margin on this page is therefore a ceiling</strong
                >
                — the real figure is lower. Add the rate under
                <code>admin.reporting.fx</code> to close the gap.
            </p>
        </div>

        <EmptyState
            v-if="!report.has_data"
            :icon="Info"
            title="Nothing rolled up for this window"
            description="The figures come from a nightly command rather than a live scan. Run platform:roll-up — with --days to backfill a window — and this fills in."
        />

        <template v-else>
            <section aria-label="Headline figures">
                <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        v-for="kpi in report.kpis"
                        :key="kpi.key"
                        :kpi="kpi"
                    />
                </dl>
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <Card class="shadow-none">
                    <CardContent class="flex flex-col gap-4 px-5 py-5">
                        <div>
                            <h2 class="text-lg font-semibold text-foreground">
                                Onboarding funnel
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Of the
                                {{ number(cohortCount) }}
                                {{ cohortCount === 1 ? 'tenant' : 'tenants' }}
                                that signed up in this window, how far each got.
                                The window selects a signup
                                <strong>cohort</strong>, not events — so "ran a
                                discovery" means of those tenants, how many have
                                since run one.
                            </p>
                        </div>
                        <FunnelChart :steps="report.funnel" />
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-6">
                    <Card class="shadow-none">
                        <CardContent class="flex flex-col gap-4 px-5 py-5">
                            <h2 class="text-lg font-semibold text-foreground">
                                Signups by market
                            </h2>
                            <BarListChart
                                v-if="report.signups_by_market.length > 0"
                                :slices="report.signups_by_market"
                                tone="bg-chart-1"
                                show-share
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                Nobody signed up in this window.
                            </p>
                        </CardContent>
                    </Card>

                    <Card class="shadow-none">
                        <CardContent class="flex flex-col gap-4 px-5 py-5">
                            <div>
                                <h2
                                    class="text-lg font-semibold text-foreground"
                                >
                                    How long conversions take
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    In day order rather than by volume — the
                                    shape is the signal.
                                </p>
                            </div>
                            <BarListChart
                                v-if="report.conversion_days.length > 0"
                                :slices="report.conversion_days"
                                tone="bg-chart-3"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                Nobody converted in this window.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section class="flex flex-col gap-5" aria-label="Reports">
                <div class="flex flex-wrap items-center gap-2">
                    <!--
                      Buttons rather than links: switching tab changes nothing on the server, and a
                      page load per tab would re-run thirteen report builders to show one of them.
                    -->
                    <Button
                        v-for="tab in reports"
                        :key="tab.value"
                        :variant="
                            activeTab === tab.value ? 'default' : 'outline'
                        "
                        size="sm"
                        :aria-pressed="activeTab === tab.value"
                        @click="activeTab = tab.value"
                    >
                        {{ tab.label }}
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        class="ms-auto"
                        as-child
                    >
                        <a :href="exportHref">
                            <Download class="size-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <Card v-if="activeTable" class="shadow-none">
                    <CardContent class="flex flex-col gap-6 px-5 py-5">
                        <ReportTableView :table="activeTable" utc />

                        <!--
                          The counting rules, beside the figures. This is the phase's risk control
                          rather than a nicety: a platform aggregate has no customer to notice it
                          has quietly answered a different question.
                        -->
                        <div
                            v-if="activeTable.definitions.length > 0"
                            class="flex flex-col gap-2 rounded-2xl bg-muted/40 px-4 py-3"
                        >
                            <p
                                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                            >
                                How these are counted
                            </p>
                            <ul
                                class="flex list-disc flex-col gap-1.5 ps-5 text-sm text-muted-foreground"
                            >
                                <li
                                    v-for="(
                                        definition, index
                                    ) in activeTable.definitions"
                                    :key="index"
                                >
                                    {{ definition }}
                                </li>
                            </ul>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <footer class="text-xs text-muted-foreground">
                <p>
                    Money is shown in {{ report.currency }}. Provider cost is
                    recorded in the currency the provider billed and converted
                    at a <strong>configured</strong> rate, not a market one:
                    {{ fxNote || 'no rates configured' }}.
                </p>
            </footer>
        </template>
    </div>
</template>
