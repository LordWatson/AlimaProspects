<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, Languages, LogOut, Settings, Users } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useI18n } from '@/composables/useI18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { switchMethod as switchTeam } from '@/routes/teams';
import type { User } from '@/types';

type Props = {
    user: User;
};

const { t, locale, available, setLocale } = useI18n();

const page = usePage();

// Only worth a menu entry when there is somewhere to switch to.
const teams = computed(() => page.props.auth.teams ?? []);

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="me-2 h-4 w-4" />
                {{ t('Settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger class="cursor-pointer">
                <Languages class="me-2 h-4 w-4" />
                {{ t('Language') }}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
                <DropdownMenuItem
                    v-for="option in available"
                    :key="option.code"
                    class="cursor-pointer gap-2"
                    @select="setLocale(option.code)"
                >
                    <span class="flex-1" :dir="option.dir">{{
                        option.native
                    }}</span>
                    <Check
                        v-if="option.code === locale"
                        class="size-4 text-brand"
                    />
                </DropdownMenuItem>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuSub v-if="teams.length > 1">
            <DropdownMenuSubTrigger class="cursor-pointer">
                <Users class="me-2 h-4 w-4" />
                {{ t('Team') }}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
                <DropdownMenuItem
                    v-for="team in teams"
                    :key="team.id"
                    class="cursor-pointer gap-2"
                    @select="router.post(switchTeam(team.id).url)"
                >
                    <span class="flex-1 truncate">{{ team.name }}</span>
                    <Check v-if="team.is_current" class="size-4 text-brand" />
                </DropdownMenuItem>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="me-2 h-4 w-4" />
            {{ t('Log out') }}
        </Link>
    </DropdownMenuItem>
</template>
