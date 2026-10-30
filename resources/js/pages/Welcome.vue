<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Search, Send, Sparkles } from '@lucide/vue';
import PandaPeek from '@/components/PandaPeek.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard, register } from '@/routes';

const { t } = useI18n();
const page = usePage();

const steps = [
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
    <Head title="Alima — Marketing brighter together" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto w-full max-w-7xl px-5 pt-6 pb-10 sm:px-8 lg:pt-10"
        >
            <p
                class="flex items-center gap-3 font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                <span
                    class="h-0.5 w-8 rounded-full bg-orange"
                    aria-hidden="true"
                />
                {{ t('Bold ideas. Real impact.') }}
            </p>

            <div class="grid items-center gap-10 lg:grid-cols-[1.25fr_0.75fr]">
                <div class="pt-6">
                    <h1
                        class="max-w-3xl text-6xl leading-[0.95] font-bold tracking-tight text-balance sm:text-7xl lg:text-8xl"
                    >
                        {{ t('Find the right prospects,') }}<br />
                        {{ t('reach them first')
                        }}<span class="text-orange">.</span>
                    </h1>
                    <p
                        class="mt-8 max-w-xl text-lg leading-relaxed text-muted-foreground"
                    >
                        {{
                            t(
                                'Alima finds, qualifies and reaches the businesses most likely to buy — with AI built for the UK and the UAE, bilingual from day one.',
                            )
                        }}
                    </p>
                    <div class="mt-10 flex flex-wrap items-center gap-3">
                        <Button as-child size="lg" variant="lime">
                            <Link
                                :href="
                                    page.props.auth.user
                                        ? dashboard()
                                        : register()
                                "
                            >
                                {{ t("Let's grow") }}
                                <ArrowRight class="size-4 rtl:rotate-180" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <a href="#how">{{ t('See how it works') }}</a>
                        </Button>
                    </div>
                </div>

                <div class="relative hidden h-64 lg:block">
                    <PandaPeek
                        class="absolute end-0 bottom-0 h-72 w-72 translate-y-10"
                    />
                </div>
            </div>

            <div class="mt-6 h-px w-full bg-ink/80" aria-hidden="true" />
        </section>

        <!-- How it works -------------------------------------------------------->
        <section
            id="how"
            class="mx-auto w-full max-w-7xl scroll-mt-24 px-5 py-14 sm:px-8 lg:py-20"
        >
            <p
                class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                {{ t('How Alima works') }}
            </p>
            <h2
                class="mt-4 max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
            >
                {{ t('Three steps to your ')
                }}<span class="text-teal">{{ t('next customer') }}</span
                ><span class="text-orange">.</span>
            </h2>

            <div class="mt-12 grid gap-10 md:grid-cols-3">
                <div v-for="s in steps" :key="s.step" class="flex flex-col">
                    <div class="h-px w-full bg-ink" aria-hidden="true" />
                    <div class="mt-4 flex items-center gap-3">
                        <span
                            class="flex size-11 items-center justify-center rounded-full bg-accent text-teal"
                            aria-hidden="true"
                        >
                            <component :is="s.icon" class="size-5" />
                        </span>
                        <span class="font-mono text-sm text-teal">{{
                            s.step
                        }}</span>
                    </div>
                    <h3
                        class="mt-4 text-xl font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(s.title) }}
                    </h3>
                    <p class="mt-3 leading-relaxed text-muted-foreground">
                        {{ t(s.body) }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Closing CTA --------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 pt-4 pb-20 sm:px-8">
            <div
                class="relative overflow-hidden rounded-3xl bg-ink px-8 py-14 text-cream sm:px-14"
            >
                <div class="relative z-10 flex flex-col items-start gap-5">
                    <h2
                        class="max-w-xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Ready to grow')
                        }}<span class="text-orange">?</span>
                    </h2>
                    <p class="max-w-md text-lg text-cream/70">
                        {{ t("Let's build your pipeline together.") }}
                    </p>
                    <Button
                        v-if="!page.props.auth.user"
                        as-child
                        size="lg"
                        variant="lime"
                    >
                        <Link :href="register()">
                            {{ t("Let's grow") }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                </div>
                <PandaPeek
                    class="absolute end-6 bottom-0 hidden h-40 w-40 translate-y-6 text-lilac sm:block"
                />
            </div>
        </section>
    </MarketingLayout>
</template>
