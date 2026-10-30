<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Search, Send, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import PandaPeek from '@/components/PandaPeek.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const { t } = useI18n();
const page = usePage();

const onboarding = computed(() => page.props.onboarding);
const showSetup = computed(
    () => onboarding.value != null && !onboarding.value.complete,
);
const hasStarted = computed(() => onboarding.value?.started ?? false);

const firstName = computed(
    () => (page.props.auth.user?.name ?? '').split(' ')[0] || t('there'),
);

const longDate = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    })
        .format(new Date())
        .toUpperCase(),
);

// The prospecting funnel — zero-state for a new account (no fabricated data).
const stats = [
    {
        label: 'Prospects sourced',
        value: '0',
        caption: 'From Companies House, web & Places',
    },
    { label: 'Qualified', value: '0', caption: 'Scored and enriched by AI' },
    { label: 'Contacted', value: '0', caption: 'Reached in EN or Arabic' },
    { label: 'Replies', value: '0', caption: 'Conversations started' },
];

const flow = [
    {
        icon: Search,
        step: '01',
        title: 'Source',
        body: 'Find businesses that fit — from Companies House, the web and Google Places.',
    },
    {
        icon: Sparkles,
        step: '02',
        title: 'Qualify',
        body: 'AI scores and enriches every prospect, so you focus on the ones ready to talk.',
    },
    {
        icon: Send,
        step: '03',
        title: 'Reach',
        body: 'Contact prospects in the right language, at the right moment — and track every reply.',
    },
];
</script>

<template>
    <Head title="Dashboard" />

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

        <!-- Hero ---------------------------------------------------------------->
        <section
            class="relative overflow-hidden rounded-3xl bg-ink p-6 text-cream sm:p-9"
        >
            <div
                class="flex items-center justify-between font-mono text-xs tracking-eyebrow text-cream/60 uppercase rtl:tracking-normal"
            >
                <span class="flex items-center gap-2">
                    <span
                        class="size-2.5 rounded-sm bg-lime"
                        aria-hidden="true"
                    />
                    01 — {{ t('Overview') }}
                </span>
                <span>{{ longDate }}</span>
            </div>
            <div class="mt-4 h-px w-full bg-cream/15" aria-hidden="true" />

            <div
                class="mt-8 grid gap-8 lg:grid-cols-[1.5fr_0.5fr] lg:items-center"
            >
                <div class="relative z-10">
                    <h2
                        class="text-4xl leading-[0.98] font-bold tracking-tight sm:text-5xl"
                    >
                        {{ t('Good morning,') }}<br />
                        <span class="text-lime"
                            >{{ firstName
                            }}<span class="text-lime">.</span></span
                        >
                    </h2>
                    <div
                        class="mt-5 h-1 w-14 rounded-full bg-orange"
                        aria-hidden="true"
                    />
                    <p class="mt-6 max-w-lg text-cream/70">
                        {{
                            t(
                                "Here's where your prospecting stands — and what to set up next so Alima can find and reach the right businesses for you.",
                            )
                        }}
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
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
                        <Button
                            size="lg"
                            variant="inverse"
                            as-child
                            class="bg-transparent text-cream ring-1 ring-cream/30 ring-inset hover:bg-cream/10"
                        >
                            <Link href="/design">{{ t('Design system') }}</Link>
                        </Button>
                    </div>
                </div>

                <PandaPeek
                    class="relative z-0 mx-auto h-40 w-40 lg:ms-auto lg:me-0"
                />
            </div>
        </section>

        <!-- Funnel (zero-state) ------------------------------------------------->
        <section
            class="grid grid-cols-2 gap-px overflow-hidden rounded-3xl border border-border bg-border lg:grid-cols-4"
        >
            <div v-for="stat in stats" :key="stat.label" class="bg-card p-6">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t(stat.label) }}
                </p>
                <p class="mt-3 text-5xl font-bold tracking-tight">
                    {{ stat.value }}
                </p>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{ t(stat.caption) }}
                </p>
            </div>
        </section>

        <!-- Prospecting flow ---------------------------------------------------->
        <section class="rounded-3xl bg-card p-6 shadow-sm sm:p-8">
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
                </div>
            </div>
        </section>
    </div>
</template>
