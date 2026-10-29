<script setup lang="ts">
import { LayoutGrid, Palette } from '@lucide/vue';
import { computed } from 'vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const { t, isRtl } = useI18n();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('Dashboard'),
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: t('Design system'),
        href: '/design',
        icon: Palette,
    },
]);
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        :side="isRtl ? 'right' : 'left'"
    >
        <SidebarHeader>
            <TeamSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :label="t('Platform')" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
