<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Satellite, Search, Send, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import {
    BarListChart,
    FunnelChart,
    GradeMixBar,
    StatTile,
    TrendChart,
} from '@/components/charts';
import PageHero from '@/components/PageHero.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';
import type { DashboardReport } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const props = defineProps<{ report: DashboardReport }>();

const { t } = useI18n();
const page = usePage();

const onboarding = computed(() => page.props.onboarding);
const showSetup = computed(
    () => onboarding.value != null && !onboarding.value.complete,
);
const hasStarted = computed(() => onboarding.value?.started ?? false);

// The hero's one figure — what is waiting on the member, which is the question the reference's
// "NEEDS ATTENTION / 14" answers. Read off the report rather than re-counted.
const waiting = computed(
    () =>
        props.report.kpis.find((kpi) => kpi.key === 'signals_waiting')?.value ??
        0,
);

const firstName = computed(
    () => (page.props.auth.user?.name ?? '').split(' ')[0] || t('there'),
);

// The explainer a team sees until it has sourced its first lead. Showing this instead of five
// charts of zeroes is the point: a new account needs the next step, not an empty report.
const flow = [
    {
        icon: Search,
        step: '01',
        title: 'Source',
        body: 'Find businesses that fit — from Companies House, the web and Google Places.',
        href: '/discovery',
        cta: 'Run a discovery',
    },
    {
        icon: Sparkles,
        step: '02',
        title: 'Qualify',
        body: 'AI scores and enriches every prospect, so you focus on the ones ready to talk.',
        href: '/icp',
        cta: 'Set your ideal customer',
    },
    {
        icon: Send,
        step: '03',
        title: 'Reach',
        body: 'Contact prospects in the right language, at the right moment — and track every reply.',
        href: '/leads',
        cta: 'Open your leads',
    },
];
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="flex flex-col gap-5 py-2">
        <!-- First-run setup nudge (onboarding is not hard-gated) -->
        <section
            v-if="showSetup"
            class="flex flex-col items-start gap-3 rounded-2xl bg-ink p-5 text-cream sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <Sparkles class="size-5 text-lime" />
                <p class="text-sm">
                    {{
                        hasStarted
                            ? t(
                                  'Finish setting up Alima to start finding prospects.',
                              )
                            : t(
                                  'Tell us about your business so Alima can find the right prospects.',
                              )
                    }}
                </p>
            </div>
            <Button variant="lime" size="sm" as-child>
                <Link href="/onboarding" data-test="dashboard-resume-setup">
                    {{ hasStarted ? t('Resume setup') : t('Start setup') }}
                    <ArrowRight class="size-4 rtl:rotate-180" />
                </Link>
            </Button>
        </section>

        <!-- Hero -->
        <PageHero
            index="01"
            :eyebrow="t('Overview')"
            :title="t('Good morning,')"
            :accent="`${firstName}.`"
            :stat="{
                label: t('Needs attention'),
                value: waiting,
                caption: t('Signals waiting on you'),
            }"
            :description="
                report.has_data
                    ? t(
                          'Where your prospecting stands — what Alima has found, how well it fits, and what is waiting on you.',
                      )
                    : t(
                          'Here\'s where your prospecting stands — and what to set up next so Alima can find and reach the right businesses for you.',
                      )
            "
        >
            <template #actions>
                <Button variant="lime" size="lg" as-child>
                    <Link href="/onboarding">
                        {{
                            showSetup
                                ? t('Set up your business')
                                : t('View business profile')
                        }}
                        <ArrowRight class="size-4 rtl:rotate-180" />
                    </Link>
                </Button>
                <Button size="lg" variant="hero" as-child>
                    <Link href="/discovery">{{ t('Find leads') }}</Link>
                </Button>
            </template>
        </PageHero>

        <!-- Headline KPIs -->
        <section
            class="grid grid-cols-1 gap-px overflow-hidden rounded-3xl border border-border bg-border sm:grid-cols-2 xl:grid-cols-4"
            :aria-label="t('Headline numbers')"
        >
            <StatTile
                v-for="(kpi, index) in report.kpis"
                :key="kpi.key"
                :kpi="kpi"
                :tone="index === 1 ? 'text-chart-4' : 'text-chart-1'"
            />
        </section>

        <!-- The funnel -->
        <section :aria-label="t('Prospecting funnel')">
            <FunnelChart :steps="report.funnel" />
        </section>

        <template v-if="report.has_data">
            <!-- Trend + fit mix -->
            <div class="grid gap-5 xl:grid-cols-[1.6fr_1fr]">
                <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
                    <TrendChart
                        :points="report.trend"
                        :weeks="report.trend_weeks"
                    />
                </section>
                <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
                    <GradeMixBar :slices="report.grades" />
                </section>
            </div>

            <!-- Breakdowns -->
            <div class="grid gap-5 lg:grid-cols-3">
                <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p
                                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                            >
                                {{ t('Pipeline') }}
                            </p>
                            <h3
                                class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                            >
                                {{ t('Where your work sits') }}
                            </h3>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link href="/leads/pipeline">{{ t('Board') }}</Link>
                        </Button>
                    </div>
                    <div class="mt-6">
                        <BarListChart
                            v-if="report.stages.length"
                            :slices="report.stages"
                            tone="bg-chart-2"
                            show-share
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            {{ t('No stages yet.') }}
                        </p>
                    </div>
                </section>

                <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p
                                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                            >
                                {{ t('Sources') }}
                            </p>
                            <h3
                                class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                            >
                                {{ t('Where leads came from') }}
                            </h3>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link href="/discovery">{{ t('Discover') }}</Link>
                        </Button>
                    </div>
                    <div class="mt-6">
                        <BarListChart
                            v-if="report.sources.length"
                            :slices="report.sources"
                            tone="bg-chart-1"
                            show-share
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            {{ t('Nothing sourced yet.') }}
                        </p>
                    </div>
                </section>

                <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p
                                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                            >
                                {{ t('Signals') }}
                            </p>
                            <h3
                                class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                            >
                                {{ t('Trigger events found') }}
                            </h3>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link href="/signals">{{ t('Feed') }}</Link>
                        </Button>
                    </div>
                    <div class="mt-6">
                        <BarListChart
                            v-if="report.signal_types.length"
                            :slices="report.signal_types"
                            tone="bg-chart-3"
                            show-share
                        />
                        <div v-else class="flex flex-col items-start gap-4">
                            <p class="text-sm text-muted-foreground">
                                {{
                                    t(
                                        'No signals yet. Set up a watch and Alima will bring you funding rounds, acquisitions and relocations as they are published.',
                                    )
                                }}
                            </p>
                            <Button variant="outline" size="sm" as-child>
                                <Link href="/signals/watches">
                                    <Satellite class="size-4" />
                                    {{ t('Watch settings') }}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </template>

        <!-- Zero state: how Alima works, with the next step on each card. -->
        <section v-else class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('How Alima works') }}
            </p>
            <h3
                class="mt-2 text-2xl font-semibold tracking-tight rtl:font-bold"
            >
                {{ t('From search to signed, ')
                }}<span class="text-brand">{{ t('together') }}</span
                ><span class="text-orange">.</span>
            </h3>

            <div class="mt-8 grid gap-10 md:grid-cols-3">
                <div
                    v-for="item in flow"
                    :key="item.step"
                    class="flex flex-col"
                >
                    <div class="h-px w-full bg-ink" aria-hidden="true" />
                    <div class="mt-4 flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-full bg-accent text-brand"
                            aria-hidden="true"
                        >
                            <component :is="item.icon" class="size-5" />
                        </span>
                        <span class="font-mono text-sm text-brand">{{
                            item.step
                        }}</span>
                    </div>
                    <h4
                        class="mt-4 text-lg font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(item.title) }}
                    </h4>
                    <p class="mt-2 leading-relaxed text-muted-foreground">
                        {{ t(item.body) }}
                    </p>
                    <Button
                        variant="link"
                        size="sm"
                        as-child
                        class="mt-4 self-start px-0"
                    >
                        <Link :href="item.href">
                            {{ t(item.cta) }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
