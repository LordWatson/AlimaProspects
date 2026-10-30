<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import PandaPeek from '@/components/PandaPeek.vue';
import { useI18n } from '@/composables/useI18n';
import { home } from '@/routes';

/*
 * On-brand split auth shell, composed to the reference screen in
 * `resources/screenshots/`. Left: an Acid Lime editorial panel — a mono
 * masthead rule at the top, the brand statement set in the open middle, and the
 * oversized "alima" wordmark sized to the panel's measure and bled off the
 * bottom edge. Right: the Cloud Cream form column — brand eyebrow, page title
 * and description centred, a mono sign-off on the bottom line and the one lilac
 * panda "peek" cropped by the bottom end corner (§5: one peek, one colour per
 * layout, so the lime panel carries none). Bilingual (EN/AR + RTL); logical
 * properties mirror cleanly and the logo never mirrors.
 */
const { t } = useI18n();

defineProps<{
    title?: string;
    description?: string;
}>();

const year = new Date().getFullYear();
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <!-- Lime editorial panel (hidden on small screens) -->
        <div
            class="relative hidden flex-col overflow-hidden bg-lime p-10 text-ink lg:flex"
        >
            <!-- Masthead -->
            <div>
                <div
                    class="flex items-center justify-between font-mono text-xs tracking-eyebrow uppercase rtl:tracking-normal"
                >
                    <span>{{ t('Alima') }}</span>
                    <span class="hidden xl:inline">{{
                        t('AI prospecting')
                    }}</span>
                    <span>V1.0</span>
                </div>
                <div class="mt-4 h-px w-full bg-ink/80" aria-hidden="true" />
            </div>

            <!-- Statement, set in the open space between masthead and wordmark.
                 `pb-12` is optical centring: the measured reference sits the
                 block a little above the mathematical middle. -->
            <div class="flex flex-1 flex-col justify-center pb-12">
                <p class="text-5xl leading-[0.95] font-bold tracking-tight">
                    {{ t('Marketing') }}<br />{{ t('brighter together')
                    }}<span class="text-ink">.</span>
                </p>
                <p
                    class="mt-6 font-mono text-xs tracking-eyebrow uppercase rtl:tracking-normal"
                >
                    {{ t('Source · Qualify · Reach · Grow') }}
                </p>
            </div>

            <!-- Oversized wordmark, sitting on the panel's bottom padding line.
                 The size is the panel's own measure: the panel is half the
                 viewport from `lg` up, and "alima" sets 2.82em wide at this
                 weight and tracking, so (50vw − the two 2.5rem paddings) ÷ 2.82
                 spans the measure exactly at every width. `leading-[0.7]` puts
                 the baseline on the padding line. -->
            <div dir="ltr" class="select-none" aria-hidden="true">
                <span
                    class="block text-[calc((50vw_-_5rem)/2.82)] leading-[0.7] font-bold tracking-tight text-ink"
                >
                    alima
                </span>
            </div>
        </div>

        <!-- Form column -->
        <div
            class="relative flex flex-col overflow-hidden bg-background p-6 md:p-10"
        >
            <div class="absolute end-5 top-5 z-10">
                <LocaleToggle />
            </div>

            <!-- The one peek: lilac, cropped by the bottom and end edges -->
            <PandaPeek
                class="absolute -end-10 -bottom-10 aspect-[383/278] w-44"
            />

            <div class="flex flex-1 items-center justify-center">
                <div class="relative flex w-full max-w-md flex-col gap-8">
                    <Link
                        :href="home()"
                        class="w-fit rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none lg:hidden"
                    >
                        <AppLogo />
                    </Link>

                    <div class="flex flex-col gap-3">
                        <p
                            class="flex items-center gap-3 font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                        >
                            <span
                                class="h-0.5 w-8 rounded-full bg-orange"
                                aria-hidden="true"
                            />
                            {{ t('Alima') }}
                        </p>
                        <h1
                            v-if="title"
                            class="text-4xl font-bold tracking-tight rtl:font-bold"
                        >
                            {{ t(title) }}
                        </h1>
                        <p v-if="description" class="text-muted-foreground">
                            {{ t(description) }}
                        </p>
                    </div>

                    <slot />
                </div>
            </div>

            <p
                class="relative mt-10 font-mono text-xs tracking-eyebrow text-ink/55 uppercase rtl:tracking-normal"
            >
                {{ t('© :year Alima. Marketing brighter together.', { year }) }}
            </p>
        </div>
    </div>
</template>
