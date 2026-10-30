<script setup lang="ts">
import { PanelLeft } from '@lucide/vue';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { useI18n } from '@/composables/useI18n';
import type { BreadcrumbItem } from '@/types';

/*
 * Alima app shell — a Midnight Ink canvas with two inset rounded panels: the
 * deep-teal sidebar rail and the Cloud Cream content surface, which carries a
 * light topbar. On small screens the rail collapses into a drawer. Bilingual
 * via logical properties; RTL mirrors cleanly.
 */
const { t, isRtl } = useI18n();

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const title = computed(() => props.breadcrumbs.at(-1)?.title ?? t('Dashboard'));

const today = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    })
        .format(new Date())
        .replace(',', '')
        .toUpperCase(),
);
</script>

<template>
    <div class="flex min-h-svh gap-0 bg-ink text-cream lg:gap-3 lg:p-3">
        <!-- Desktop rail -->
        <aside
            class="hidden w-[17rem] shrink-0 overflow-hidden rounded-3xl lg:block"
        >
            <AppSidebar />
        </aside>

        <!-- Content surface -->
        <div
            class="flex min-w-0 flex-1 flex-col overflow-hidden bg-background text-foreground lg:rounded-3xl"
        >
            <!-- Topbar -->
            <header class="flex h-20 shrink-0 items-center gap-3 px-4 sm:px-6">
                <!-- Mobile drawer -->
                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            :aria-label="t('Menu')"
                        >
                            <PanelLeft class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        :side="isRtl ? 'right' : 'left'"
                        class="w-[17rem] border-0 bg-teal p-0 text-cream"
                    >
                        <SheetTitle class="sr-only">{{ t('Menu') }}</SheetTitle>
                        <AppSidebar />
                    </SheetContent>
                </Sheet>

                <h1 class="truncate text-lg font-semibold tracking-tight">
                    {{ title }}
                </h1>

                <div class="ms-auto flex items-center gap-2 sm:gap-3">
                    <span
                        class="hidden font-mono text-xs tracking-eyebrow text-muted-foreground sm:inline rtl:tracking-normal"
                    >
                        {{ today }}
                    </span>
                    <LocaleToggle />
                    <AppearanceToggle />
                </div>
            </header>

            <main class="flex-1 overflow-y-auto px-4 pb-6 sm:px-6">
                <slot />
            </main>
        </div>

        <Toaster />
    </div>
</template>
