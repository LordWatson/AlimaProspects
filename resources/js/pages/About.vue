<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Sun, Type, Users, Zap } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { register } from '@/routes';

const { t } = useI18n();

// The brand's four voice principles (brand-tokens.md §6) as our values.
const values = [
    { icon: Sun, title: 'Bright', body: 'We lead with the opportunity.' },
    { icon: Zap, title: 'Bold', body: 'We make the call, and explain why.' },
    {
        icon: Type,
        title: 'Clear',
        body: 'Plain words. Short sentences. Zero jargon.',
    },
    {
        icon: Users,
        title: 'Together',
        body: 'We build with people — we, you, us.',
    },
];

const markets = ['United Kingdom', 'United Arab Emirates'];
</script>

<template>
    <Head :title="t('About Alima')" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto w-full max-w-4xl px-5 py-16 text-center sm:px-8 lg:py-24"
        >
            <span
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('About Alima') }}
            </span>
            <h1
                class="mx-auto mt-5 max-w-3xl text-4xl leading-[1.08] font-bold tracking-tight text-balance sm:text-5xl rtl:font-bold"
            >
                {{ t('We help businesses grow — brighter, bolder, together')
                }}<span class="text-orange">.</span>
            </h1>
            <p
                class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground"
            >
                {{
                    t(
                        'Alima is an AI prospecting platform for teams in the UK and the UAE. We find the right businesses, qualify them, and help you start the right conversations — in English or Arabic.',
                    )
                }}
            </p>
        </section>

        <!-- Values -------------------------------------------------------------->
        <section class="border-t border-border/60 bg-card">
            <div class="mx-auto w-full max-w-6xl px-5 py-16 sm:px-8 lg:py-24">
                <div class="flex flex-col gap-3">
                    <span
                        class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                    >
                        {{ t('What we believe') }}
                    </span>
                    <h2
                        class="max-w-xl text-3xl font-semibold tracking-tight sm:text-4xl rtl:font-bold"
                    >
                        {{ t('Four principles behind every decision.') }}
                    </h2>
                </div>

                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="value in values"
                        :key="value.title"
                        class="flex flex-col gap-4 rounded-2xl border border-border bg-background p-7 shadow-sm"
                    >
                        <div
                            class="flex size-12 items-center justify-center rounded-full bg-brand text-brand-foreground"
                        >
                            <component :is="value.icon" class="size-6" />
                        </div>
                        <h3
                            class="text-lg font-semibold tracking-tight rtl:font-bold"
                        >
                            {{ t(value.title) }}
                        </h3>
                        <p class="leading-relaxed text-muted-foreground">
                            {{ t(value.body) }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Markets ------------------------------------------------------------->
        <section class="mx-auto w-full max-w-6xl px-5 py-16 sm:px-8 lg:py-24">
            <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                <div class="flex flex-col gap-5">
                    <h2
                        class="max-w-md text-3xl font-semibold tracking-tight sm:text-4xl rtl:font-bold"
                    >
                        {{ t('Built for two markets.') }}
                    </h2>
                    <p
                        class="max-w-md text-lg leading-relaxed text-muted-foreground"
                    >
                        {{
                            t(
                                'Alima works in English and Arabic with full right-to-left support, and respects UK and UAE data rules from the start.',
                            )
                        }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="market in markets"
                        :key="market"
                        class="flex items-center gap-4 rounded-2xl bg-ink p-6 text-cream"
                    >
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-teal text-cream"
                            aria-hidden="true"
                        >
                            <AppLogoIcon class="size-6" />
                        </div>
                        <span class="text-lg font-semibold">{{
                            t(market)
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA ---------------------------------------------------------------->
        <section class="mx-auto w-full max-w-6xl px-5 pb-20 sm:px-8">
            <div
                class="flex flex-col items-start gap-5 rounded-3xl border border-border bg-card px-8 py-12 sm:px-14"
            >
                <h2
                    class="max-w-xl text-3xl font-bold tracking-tight sm:text-4xl rtl:font-bold"
                >
                    {{ t('Ready to grow?') }}
                </h2>
                <Button as-child size="lg" variant="brand">
                    <Link :href="register()">{{ t("Let's grow") }}</Link>
                </Button>
            </div>
        </section>
    </MarketingLayout>
</template>
