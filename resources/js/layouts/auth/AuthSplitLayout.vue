<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import { useI18n } from '@/composables/useI18n';
import { home } from '@/routes';

/*
 * On-brand split auth layout. Left: a Midnight Ink brand panel (cream logo
 * colourway — never Teal-on-Ink — with the tagline and a spectrum rule). Right:
 * the Cloud Cream form column with the page title/description. Bilingual: a
 * locale toggle sits in the top-inline-end corner; logical properties mirror
 * cleanly under RTL (the logo lockup is forced dir=ltr and never mirrors).
 */
const { t } = useI18n();

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <!-- Brand panel (hidden on small screens) -->
        <div
            class="relative hidden flex-col justify-between overflow-hidden bg-ink p-10 text-cream lg:flex"
        >
            <Link
                :href="home()"
                class="relative z-10 w-fit rounded-md focus-visible:ring-[3px] focus-visible:ring-blue focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <div
                    dir="ltr"
                    class="flex items-center gap-2"
                    role="img"
                    aria-label="Alima"
                >
                    <div
                        class="flex size-9 items-center justify-center rounded-md bg-cream text-teal"
                        aria-hidden="true"
                    >
                        <AppLogoIcon class="size-6" />
                    </div>
                    <span
                        class="text-lg font-semibold tracking-tight text-cream"
                        aria-hidden="true"
                        >alima</span
                    >
                </div>
            </Link>

            <div class="relative z-10 flex flex-col gap-4">
                <p class="max-w-sm text-3xl leading-tight font-semibold">
                    {{ t('Marketing brighter together.') }}
                </p>
                <p
                    class="font-mono text-xs tracking-[0.25em] text-cream/70 uppercase rtl:tracking-normal"
                >
                    {{ t('Bold ideas. Real impact.') }}
                </p>
            </div>

            <div
                class="absolute inset-x-0 bottom-0 h-2 bg-spectrum"
                aria-hidden="true"
            />
        </div>

        <!-- Form column -->
        <div
            class="relative flex flex-col items-center justify-center bg-background p-6 md:p-10"
        >
            <div class="absolute end-5 top-5">
                <LocaleToggle />
            </div>

            <div class="flex w-full max-w-sm flex-col gap-8">
                <div class="flex flex-col gap-4">
                    <Link
                        :href="home()"
                        class="w-fit rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none lg:hidden"
                    >
                        <AppLogo />
                    </Link>
                    <div class="flex flex-col gap-2">
                        <h1
                            v-if="title"
                            class="text-2xl font-semibold tracking-tight"
                        >
                            {{ t(title) }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ t(description) }}
                        </p>
                    </div>
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>
