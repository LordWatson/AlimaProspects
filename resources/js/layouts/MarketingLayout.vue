<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { dashboard, home, login, register } from '@/routes';

/*
 * Public marketing shell (landing + about). Leads with Cloud Cream, Deep Teal
 * structure, Midnight Ink text; a single thin spectrum rule at the very top is
 * the one brand signature (brand-tokens.md §1.4). Header 88px, logo, one primary
 * CTA per view (§2.3). Fully bilingual (EN/AR + RTL) via logical properties.
 */
const { t } = useI18n();
const page = usePage();

const nav = [
    { label: 'Home', href: home().url },
    { label: 'About', href: '/about' },
];
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <!-- Brand spectrum signature rule (fixed stop order, never reordered) -->
        <div class="h-1 w-full shrink-0 bg-spectrum" aria-hidden="true" />

        <header
            class="sticky top-0 z-40 border-b border-border/60 bg-background/80 backdrop-blur"
        >
            <div
                class="mx-auto flex h-[88px] w-full max-w-6xl items-center gap-6 px-5 sm:px-8"
            >
                <Link
                    :href="home()"
                    class="shrink-0 rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <AppLogo />
                </Link>

                <nav
                    class="ms-4 hidden items-center gap-1 md:flex"
                    :aria-label="t('Menu')"
                >
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="rounded-full px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ t(item.label) }}
                    </Link>
                </nav>

                <div class="ms-auto flex items-center gap-2">
                    <LocaleToggle />

                    <!-- Compact menu for small screens (nav links live in the header on md+) -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="md:hidden"
                                :aria-label="t('Menu')"
                            >
                                <Menu class="size-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="min-w-44">
                            <DropdownMenuItem
                                v-for="item in nav"
                                :key="item.href"
                                as-child
                            >
                                <Link :href="item.href">{{
                                    t(item.label)
                                }}</Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="!page.props.auth.user"
                                as-child
                            >
                                <Link :href="login()">{{ t('Log in') }}</Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <template v-if="page.props.auth.user">
                        <Button as-child variant="outline">
                            <Link :href="dashboard()">{{
                                t('Dashboard')
                            }}</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            as-child
                            variant="ghost"
                            class="hidden sm:inline-flex"
                        >
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                        <Button as-child variant="outline">
                            <Link :href="register()">{{
                                t("Let's grow")
                            }}</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-border/60">
            <div
                class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-5 py-10 sm:flex-row sm:items-center sm:justify-between sm:px-8"
            >
                <AppLogo />
                <nav
                    class="flex items-center gap-5 text-sm text-muted-foreground"
                    :aria-label="t('Menu')"
                >
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="rounded transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ t(item.label) }}
                    </Link>
                    <Link
                        :href="login()"
                        class="rounded transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ t('Log in') }}
                    </Link>
                </nav>
                <p class="font-mono text-xs text-muted-foreground">
                    © {{ new Date().getFullYear() }} Alima.
                    {{ t('All rights reserved.') }}
                </p>
            </div>
        </footer>
    </div>
</template>
