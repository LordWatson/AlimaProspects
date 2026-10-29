<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import PandaPeek from '@/components/PandaPeek.vue';
import { useI18n } from '@/composables/useI18n';
import { home } from '@/routes';

/*
 * On-brand split auth shell. Left: an Acid Lime editorial panel — a mono
 * masthead, the brand statement and the oversized "alima" wordmark bled off the
 * bottom edge, with a lilac panda "peek". Right: the Cloud Cream form column
 * with the brand eyebrow, page title and description. Bilingual (EN/AR + RTL);
 * logical properties mirror cleanly and the logo never mirrors.
 */
const { t } = useI18n();

defineProps<{
    title?: string;
    description?: string;
}>();
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

            <!-- Statement -->
            <div class="mt-24">
                <p
                    class="max-w-md text-6xl leading-[0.95] font-bold tracking-tight"
                >
                    {{ t('Marketing') }}<br />{{ t('brighter together')
                    }}<span class="text-ink">.</span>
                </p>
                <p
                    class="mt-6 font-mono text-xs tracking-eyebrow uppercase rtl:tracking-normal"
                >
                    {{ t('Source · Qualify · Reach · Grow') }}
                </p>
            </div>

            <!-- Oversized wordmark, bled off the bottom -->
            <div
                dir="ltr"
                class="relative mt-auto select-none"
                aria-hidden="true"
            >
                <PandaPeek
                    class="absolute end-2 bottom-2 h-28 w-28 translate-y-6"
                />
                <span
                    class="block text-[11rem] leading-[0.7] font-bold tracking-tight text-ink"
                >
                    alima
                </span>
            </div>
        </div>

        <!-- Form column -->
        <div
            class="relative flex flex-col items-center justify-center bg-background p-6 md:p-10"
        >
            <div class="absolute end-5 top-5">
                <LocaleToggle />
            </div>

            <div class="flex w-full max-w-md flex-col gap-8">
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
    </div>
</template>
