<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, LogOut } from '@lucide/vue';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogo from '@/components/AppLogo.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { useI18n } from '@/composables/useI18n';
import { dashboard, logout } from '@/routes';

/*
 * A focused, on-brand shell for the onboarding wizard — Cloud Cream canvas, a big logo and the
 * language/appearance controls, with no app sidebar so a new team can concentrate on setup. Once
 * onboarding is complete, a quiet "Back to dashboard" link appears. Mounts the toast host so the
 * completion and error toasts show.
 */
const { t } = useI18n();
const page = usePage();
const isComplete = computed(() => page.props.onboarding?.complete ?? false);
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="flex items-center justify-between gap-4 border-b border-border/60 px-4 py-4 sm:px-8"
        >
            <AppLogo />
            <div class="flex items-center gap-1">
                <Button v-if="isComplete" variant="ghost" size="sm" as-child>
                    <Link :href="dashboard()">
                        <LayoutGrid class="size-4" />
                        {{ t('Back to dashboard') }}
                    </Link>
                </Button>
                <LocaleToggle />
                <AppearanceToggle />
                <Button
                    variant="ghost"
                    size="icon"
                    as-child
                    :aria-label="t('Log out')"
                >
                    <Link
                        :href="logout()"
                        as="button"
                        data-test="logout-button"
                    >
                        <LogOut class="size-5" />
                    </Link>
                </Button>
            </div>
        </header>

        <main class="flex flex-1 justify-center px-4 py-8 sm:px-8 sm:py-12">
            <div class="w-full max-w-5xl">
                <slot />
            </div>
        </main>

        <Toaster />
    </div>
</template>
