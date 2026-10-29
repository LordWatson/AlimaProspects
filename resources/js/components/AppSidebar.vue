<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChevronsUpDown,
    LayoutGrid,
    Palette,
    Radar,
    Scan,
    Settings2,
    Target,
    type LucideIcon,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import PandaFace from '@/components/PandaFace.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

/*
 * Alima app sidebar — a deep-teal editorial rail. The active item is an Acid
 * Lime pill (lime is the shell's hero accent — decorative, never a status).
 * Shared by the desktop rail and the mobile drawer. Only real destinations
 * appear here. Logical properties mirror under RTL; the logo never mirrors.
 */
const { t } = useI18n();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();

type Item = {
    n?: string;
    title: string;
    icon: LucideIcon;
    href: string;
    match?: 'exact' | 'startsWith';
};

const workspace = computed<Item[]>(() => [
    { n: '01', title: t('Dashboard'), icon: LayoutGrid, href: dashboard().url },
    {
        n: '02',
        title: t('Business profile'),
        icon: Building2,
        href: '/onboarding',
        match: 'startsWith',
    },
    {
        n: '03',
        title: t('Background check'),
        icon: Scan,
        href: '/business-intel',
        match: 'startsWith',
    },
    {
        n: '04',
        title: t('Ideal customers'),
        icon: Target,
        href: '/icp',
        match: 'startsWith',
    },
    {
        n: '05',
        title: t('Lead discovery'),
        icon: Radar,
        href: '/discovery',
        match: 'startsWith',
    },
    { n: '06', title: t('Design system'), icon: Palette, href: '/design' },
]);

const account = computed<Item[]>(() => [
    {
        title: t('Settings'),
        icon: Settings2,
        href: editProfile().url,
        match: 'startsWith',
    },
]);

const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .map((p) => p[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const isActive = (item: Item): boolean =>
    item.match === 'startsWith'
        ? isCurrentOrParentUrl(item.href)
        : isCurrentUrl(item.href);
</script>

<template>
    <div class="flex h-full flex-col bg-teal text-cream">
        <!-- Brand header -->
        <div class="px-5 pt-6 pb-5">
            <Link
                :href="dashboard()"
                class="inline-flex rounded-md focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
            >
                <AppLogo tone="dark" />
            </Link>
        </div>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto px-3 pb-4" :aria-label="t('Menu')">
            <p
                class="px-3 pt-2 pb-2 font-mono text-[0.625rem] tracking-eyebrow text-cream/45 uppercase rtl:tracking-normal"
            >
                {{ t('Workspace') }}
            </p>
            <ul class="space-y-0.5">
                <li v-for="item in workspace" :key="item.title">
                    <Link
                        :href="item.href"
                        class="group flex w-full items-center gap-3 rounded-full px-3 py-2.5 text-start text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
                        :class="
                            isActive(item)
                                ? 'bg-lime text-ink'
                                : 'text-cream/85 hover:bg-cream/10'
                        "
                    >
                        <component
                            :is="item.icon"
                            class="size-[18px] shrink-0"
                        />
                        <span class="flex-1 truncate">{{ item.title }}</span>
                        <span
                            class="font-mono text-[0.625rem]"
                            :class="
                                isActive(item) ? 'text-ink/60' : 'text-cream/35'
                            "
                            >{{ item.n }}</span
                        >
                    </Link>
                </li>
            </ul>

            <p
                class="px-3 pt-6 pb-2 font-mono text-[0.625rem] tracking-eyebrow text-cream/45 uppercase rtl:tracking-normal"
            >
                {{ t('Account') }}
            </p>
            <ul class="space-y-0.5">
                <li v-for="item in account" :key="item.title">
                    <Link
                        :href="item.href"
                        class="group flex w-full items-center gap-3 rounded-full px-3 py-2.5 text-start text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
                        :class="
                            isActive(item)
                                ? 'bg-lime text-ink'
                                : 'text-cream/85 hover:bg-cream/10'
                        "
                    >
                        <component
                            :is="item.icon"
                            class="size-[18px] shrink-0"
                        />
                        <span class="flex-1 truncate">{{ item.title }}</span>
                    </Link>
                </li>
            </ul>
        </nav>

        <!-- Footer: peek watermark + tagline + user -->
        <div class="relative px-5 pt-2 pb-5">
            <div
                class="pointer-events-none absolute inset-x-0 -top-4 flex justify-center overflow-hidden opacity-10"
                aria-hidden="true"
            >
                <PandaFace class="h-24 w-24 text-cream" />
            </div>

            <p
                class="relative font-mono text-[0.625rem] leading-relaxed tracking-eyebrow text-cream/40 uppercase rtl:tracking-normal"
            >
                {{ t('Marketing brighter together.') }}
            </p>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="relative mt-4 flex w-full items-center gap-3 rounded-2xl border border-cream/15 bg-cream/5 p-2.5 text-start transition-colors hover:bg-cream/10 focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-lilac text-sm font-semibold text-ink"
                        >
                            {{ initials }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-semibold text-cream"
                                >{{ user?.name }}</span
                            >
                            <span
                                class="block truncate text-xs text-cream/50"
                                >{{ user?.email }}</span
                            >
                        </span>
                        <ChevronsUpDown class="size-4 shrink-0 text-cream/50" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-64 rounded-xl"
                    align="end"
                    side="top"
                    :side-offset="8"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
