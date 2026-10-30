<script setup lang="ts">
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ms-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ms-auto flex items-center gap-1">
            <!-- The bell belongs to the shell, not to a screen: it reads the shared
                 `notifications` prop, so it is correct on every page (Phase 12). -->
            <NotificationBell />
            <LocaleToggle />
            <AppearanceToggle />
        </div>
    </header>
</template>
