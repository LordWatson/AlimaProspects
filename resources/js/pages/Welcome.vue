<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Search, Send, Sparkles } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard, register } from '@/routes';

const { t } = useI18n();
const page = usePage();

const steps = [
    {
        icon: Search,
        title: 'Source',
        body: 'Find businesses that fit — from Companies House, the web and Google Places.',
    },
    {
        icon: Sparkles,
        title: 'Qualify',
        body: 'AI scores and enriches every prospect, so you focus on the ones ready to talk.',
    },
    {
        icon: Send,
        title: 'Reach',
        body: 'Contact prospects in the right language, at the right moment — and track every reply.',
    },
];
</script>

<template>
    <Head title="Alima — Marketing brighter together" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto grid w-full max-w-6xl items-center gap-12 px-5 py-16 sm:px-8 lg:grid-cols-[1.1fr_0.9fr] lg:py-24"
        >
            <div class="flex flex-col gap-6">
                <span
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Bold ideas. Real impact.') }}
                </span>
                <h1
                    class="max-w-2xl text-5xl leading-[1.05] font-bold tracking-tight text-balance sm:text-6xl"
                >
                    {{ t('Find the right prospects.') }}
                    <span class="text-brand"
                        >{{ t('Reach them first')
                        }}<span class="text-orange">.</span></span
                    >
                </h1>
                <p
                    class="max-w-xl text-lg leading-relaxed text-muted-foreground"
                >
                    {{
                        t(
                            'Alima finds, qualifies and reaches the businesses most likely to buy — with AI built for the UK and the UAE, bilingual from day one.',
                        )
                    }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <Button
                        v-if="page.props.auth.user"
                        as-child
                        size="lg"
                        variant="brand"
                    >
                        <Link :href="dashboard()">
                            {{ t('Dashboard') }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                    <Button v-else as-child size="lg" variant="brand">
                        <Link :href="register()">
                            {{ t("Let's grow") }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                    <Button as-child size="lg" variant="outline">
                        <a href="#how">{{ t('See how it works') }}</a>
                    </Button>
                </div>
            </div>

            <!-- Inverse (Midnight Ink) panel with the snout mark -->
            <div
                class="relative overflow-hidden rounded-3xl bg-ink p-10 text-cream shadow-md"
            >
                <div class="flex flex-col items-center gap-6 text-center">
                    <div
                        class="flex size-20 items-center justify-center rounded-2xl bg-teal text-cream"
                    >
                        <AppLogoIcon class="size-12" />
                    </div>
                    <p
                        class="max-w-xs text-2xl leading-tight font-semibold rtl:font-bold"
                    >
                        {{ t('Marketing brighter together.') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- How it works -------------------------------------------------------->
        <section
            id="how"
            class="scroll-mt-24 border-t border-border/60 bg-card"
        >
            <div class="mx-auto w-full max-w-6xl px-5 py-16 sm:px-8 lg:py-24">
                <div class="flex flex-col gap-3">
                    <span
                        class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                    >
                        {{ t('How Alima works') }}
                    </span>
                    <h2
                        class="max-w-xl text-3xl font-semibold tracking-tight sm:text-4xl rtl:font-bold"
                    >
                        {{ t('Three steps to your next customer.') }}
                    </h2>
                </div>

                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    <div
                        v-for="step in steps"
                        :key="step.title"
                        class="flex flex-col gap-4 rounded-2xl border border-border bg-background p-7 shadow-sm"
                    >
                        <div
                            class="flex size-12 items-center justify-center rounded-full bg-brand text-brand-foreground"
                        >
                            <component :is="step.icon" class="size-6" />
                        </div>
                        <h3
                            class="text-xl font-semibold tracking-tight rtl:font-bold"
                        >
                            {{ t(step.title) }}
                        </h3>
                        <p class="leading-relaxed text-muted-foreground">
                            {{ t(step.body) }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Closing CTA band ---------------------------------------------------->
        <section class="mx-auto w-full max-w-6xl px-5 py-16 sm:px-8 lg:py-20">
            <div
                class="relative overflow-hidden rounded-3xl bg-ink px-8 py-14 text-cream sm:px-14"
            >
                <div class="relative z-10 flex flex-col items-start gap-5">
                    <h2
                        class="max-w-xl text-3xl font-bold tracking-tight sm:text-4xl rtl:font-bold"
                    >
                        {{ t('Ready to grow?') }}
                    </h2>
                    <p class="max-w-md text-lg text-cream/80">
                        {{ t("Let's build it together.") }}
                    </p>
                    <Button
                        v-if="!page.props.auth.user"
                        as-child
                        size="lg"
                        variant="inverse"
                    >
                        <Link :href="register()">
                            {{ t("Let's grow") }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                </div>
                <div
                    class="absolute inset-x-0 bottom-0 h-2 bg-spectrum"
                    aria-hidden="true"
                />
            </div>
        </section>
    </MarketingLayout>
</template>
