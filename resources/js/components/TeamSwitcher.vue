<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

const page = usePage();
const { isMobile, state } = useSidebar();

const currentTeam = computed(() => page.props.auth.currentTeam ?? null);
const teams = computed(() => page.props.auth.teams ?? []);

function switchTeam(id: number): void {
    if (id === currentTeam.value?.id) {
        return;
    }

    router.post(`/teams/${id}/switch`, {}, { preserveScroll: true });
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="team-switcher"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <AppLogoIcon
                                class="size-5 fill-current text-white dark:text-black"
                            />
                        </div>
                        <div
                            class="ml-1 grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-semibold">{{
                                currentTeam?.name ?? 'Select a team'
                            }}</span>
                            <span
                                v-if="currentTeam?.role"
                                class="truncate text-xs text-muted-foreground capitalize"
                                >{{ currentTeam.role }}</span
                            >
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Teams
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="team in teams"
                        :key="team.id"
                        class="gap-2"
                        @select="switchTeam(team.id)"
                    >
                        <span class="flex-1 truncate">{{ team.name }}</span>
                        <Check
                            v-if="team.is_current"
                            class="size-4 text-foreground"
                        />
                    </DropdownMenuItem>

                    <DropdownMenuSeparator />

                    <DropdownMenuItem as-child>
                        <Link href="/settings/team" class="gap-2">
                            <Settings2 class="size-4" />
                            <span>Manage team</span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link href="/settings/team#create-team" class="gap-2">
                            <Plus class="size-4" />
                            <span>Create a team</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
