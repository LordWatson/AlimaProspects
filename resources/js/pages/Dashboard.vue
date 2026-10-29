<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const { t } = useI18n();
const page = usePage();

const onboarding = computed(() => page.props.onboarding);
const showSetup = computed(
    () => onboarding.value !== null && !onboarding.value.complete,
);
const hasStarted = computed(() => onboarding.value?.started ?? false);
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <!-- First-run setup nudge — shown until onboarding is complete. -->
        <section
            v-if="showSetup"
            class="relative overflow-hidden rounded-xl bg-ink p-6 text-cream sm:p-8"
        >
            <div class="relative z-10 max-w-2xl space-y-3">
                <p
                    class="flex items-center gap-2 font-mono text-xs tracking-eyebrow text-cream/70 uppercase"
                >
                    <Sparkles class="size-4" />
                    {{ t('Get started') }}
                </p>
                <h2
                    class="text-2xl font-semibold tracking-tight sm:text-3xl rtl:font-bold"
                >
                    {{
                        hasStarted
                            ? t('Finish setting up Alima.')
                            : t('Let’s set up Alima.')
                    }}
                </h2>
                <p class="text-cream/80">
                    {{
                        t(
                            'Tell us about your business so Alima can find and qualify the right prospects for you. It only takes a few minutes.',
                        )
                    }}
                </p>
                <Button variant="inverse" as-child class="mt-2">
                    <Link href="/onboarding" data-test="dashboard-resume-setup">
                        {{ hasStarted ? t('Resume setup') : t('Start setup') }}
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>
            <!-- Spectrum signature rule along the bottom edge (never behind the body text). -->
            <div
                class="pointer-events-none absolute inset-x-0 bottom-0 h-1 bg-spectrum"
                aria-hidden="true"
            />
        </section>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
