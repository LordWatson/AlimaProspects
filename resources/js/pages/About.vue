<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    LayoutGrid,
    MessageSquare,
    Sparkles,
} from '@lucide/vue';
import PandaPeek from '@/components/PandaPeek.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { login, register } from '@/routes';

const { t } = useI18n();

const features = [
    {
        icon: Building2,
        title: 'Sourcing from real data',
        body: 'Find businesses from Companies House, the web and Google Places — no paid enrichment needed.',
    },
    {
        icon: Sparkles,
        title: 'AI qualification',
        body: 'Every prospect is scored and enriched, so you spend time only on the ones ready to buy.',
    },
    {
        icon: MessageSquare,
        title: 'Bilingual outreach',
        body: 'Reach prospects in English or Arabic, with full right-to-left support from day one.',
    },
    {
        icon: LayoutGrid,
        title: 'One pipeline',
        body: 'Sourcing, qualifying, outreach and replies — tracked together in a single workspace.',
    },
];

const steps = [
    {
        n: '01',
        title: 'Source',
        body: 'Pull in businesses that fit your criteria from Companies House, the web and Places.',
    },
    {
        n: '02',
        title: 'Qualify',
        body: 'AI scores and enriches each prospect so your team focuses on the best fits.',
    },
    {
        n: '03',
        title: 'Reach',
        body: 'Contact prospects in the right language, at the right moment — all from one place.',
    },
    {
        n: '04',
        title: 'Track',
        body: 'Every reply and status rolls back to one source of truth for the whole team.',
    },
];
</script>

<template>
    <Head :title="t('About Alima')" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto w-full max-w-7xl px-5 pt-16 pb-10 sm:px-8 lg:pt-24"
        >
            <p
                class="flex items-center gap-3 font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                <span
                    class="h-0.5 w-8 rounded-full bg-orange"
                    aria-hidden="true"
                />
                {{ t('About Alima') }}
            </p>

            <div class="grid items-center gap-10 lg:grid-cols-[1.25fr_0.75fr]">
                <div class="pt-6">
                    <h1
                        class="max-w-2xl text-5xl leading-[0.98] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl"
                    >
                        {{ t('Prospecting,') }}<br />
                        {{ t('run end to end')
                        }}<span class="text-orange">.</span>
                    </h1>
                    <p
                        class="mt-8 max-w-xl text-lg leading-relaxed text-muted-foreground"
                    >
                        {{
                            t(
                                'Alima brings sourcing, AI qualification, bilingual outreach and reply tracking together in one workspace — built for teams selling in the UK and the UAE.',
                            )
                        }}
                    </p>
                </div>
                <div class="relative hidden h-64 lg:block">
                    <PandaPeek
                        class="absolute end-0 bottom-0 h-72 w-72 translate-y-10"
                    />
                </div>
            </div>

            <div class="mt-6 h-px w-full bg-ink/80" aria-hidden="true" />
        </section>

        <!-- Everything you need ------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 lg:py-20">
            <p
                class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                {{ t('Everything you need') }}
            </p>
            <h2
                class="mt-4 max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
            >
                {{ t('One workspace,') }}<br />
                <span class="text-teal">{{ t('every part') }}</span>
                {{ t('of the hunt') }}<span class="text-ink">.</span>
            </h2>

            <div
                class="mt-12 grid gap-px overflow-hidden rounded-3xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="flex flex-col gap-4 bg-card p-7"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-full bg-accent text-teal"
                        aria-hidden="true"
                    >
                        <component :is="feature.icon" class="size-6" />
                    </div>
                    <h3
                        class="text-lg font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(feature.title) }}
                    </h3>
                    <p class="leading-relaxed text-muted-foreground">
                        {{ t(feature.body) }}
                    </p>
                </div>
            </div>
        </section>

        <!-- How it works -------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <p
                class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                {{ t('How it works') }}
            </p>
            <h2
                class="mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
            >
                {{ t('From search to signed, ')
                }}<span class="text-teal">{{ t('together') }}</span
                ><span class="text-orange">.</span>
            </h2>

            <div class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="step in steps" :key="step.n" class="flex flex-col">
                    <div class="h-px w-full bg-ink" aria-hidden="true" />
                    <span class="mt-4 font-mono text-sm text-teal">{{
                        step.n
                    }}</span>
                    <h3
                        class="mt-3 text-xl font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(step.title) }}
                    </h3>
                    <p class="mt-3 leading-relaxed text-muted-foreground">
                        {{ t(step.body) }}
                    </p>
                </div>
            </div>
        </section>

        <!-- CTA band ------------------------------------------------------------>
        <section class="mx-auto w-full max-w-7xl px-5 pt-6 pb-24 sm:px-8">
            <div
                class="grid overflow-hidden rounded-3xl lg:grid-cols-[1.1fr_0.9fr]"
            >
                <div
                    class="flex flex-col items-start justify-center gap-6 bg-ink p-10 text-cream sm:p-14"
                >
                    <h2
                        class="max-w-md text-4xl leading-[1.05] font-bold tracking-tight sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Stop guessing who to call')
                        }}<span class="text-orange">.</span>
                    </h2>
                    <p class="max-w-sm text-lg text-cream/70">
                        {{
                            t(
                                'Let Alima find, qualify and reach the right businesses for you.',
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button as-child size="lg" variant="lime">
                            <Link :href="register()">
                                {{ t("Let's grow") }}
                                <ArrowRight class="size-4 rtl:rotate-180" />
                            </Link>
                        </Button>
                        <Button
                            as-child
                            size="lg"
                            variant="inverse"
                            class="bg-transparent text-cream ring-1 ring-cream/40 ring-inset hover:bg-cream/10"
                        >
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                    </div>
                </div>

                <!-- Confetti panel (brand element — chunky loops/crosses/dots) -->
                <div class="relative min-h-56 bg-lilac" aria-hidden="true">
                    <svg
                        class="absolute inset-0 h-full w-full"
                        preserveAspectRatio="xMidYMid slice"
                        viewBox="0 0 240 200"
                    >
                        <g
                            fill="none"
                            stroke="#205557"
                            stroke-width="7"
                            stroke-linecap="round"
                        >
                            <path d="M28 40c12-14 30-4 20 10s-26 2-14-14" />
                            <path d="M120 24c16-6 26 10 10 18s-28-6-10-18" />
                            <path d="M196 54c14-10 28 6 12 16s-30-4-12-16" />
                            <path d="M60 110c18-8 26 12 8 18s-26-10-8-18" />
                            <path d="M150 120c10-16 32-8 22 8s-30 6-22-8" />
                            <path d="M206 128c12-4 20 10 6 16" />
                            <path d="M36 164c14-6 24 8 10 16s-26-8-10-16" />
                            <path d="M110 170c12-10 28 2 16 14s-30 0-16-14" />
                            <path d="M182 176c10-12 28-2 16 12" />
                            <path d="M86 62l14 14M100 62l-14 14" />
                            <path d="M170 92l14 14M184 92l-14 14" />
                            <path d="M20 108l12 12M32 108l-12 12" />
                        </g>
                        <g fill="#205557">
                            <circle cx="150" cy="60" r="5" />
                            <circle cx="64" cy="150" r="5" />
                            <circle cx="214" cy="100" r="5" />
                        </g>
                    </svg>
                </div>
            </div>
        </section>
    </MarketingLayout>
</template>
