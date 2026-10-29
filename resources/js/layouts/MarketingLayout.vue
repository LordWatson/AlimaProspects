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
 * structure, Midnight Ink text and a single dark pill CTA ("Let's talk").
 * Fully bilingual (EN/AR + RTL) via logical properties; the logo never mirrors.
 */
const { t } = useI18n();
const page = usePage();

const nav = [{ label: 'About', href: '/about' }];
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="sticky top-0 z-40 bg-background/80 backdrop-blur">
            <div
                class="mx-auto flex h-[88px] w-full max-w-7xl items-center gap-6 px-5 sm:px-8"
            >
                <Link
                    :href="home()"
                    class="shrink-0 rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <AppLogo />
                </Link>

                <div class="ms-auto flex items-center gap-2 sm:gap-6">
                    <nav
                        class="hidden items-center gap-6 md:flex"
                        :aria-label="t('Menu')"
                    >
                        <Link
                            v-for="item in nav"
                            :key="item.href"
                            :href="item.href"
                            class="rounded text-sm font-medium text-foreground/80 transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ t(item.label) }}
                        </Link>
                        <Link
                            v-if="!page.props.auth.user"
                            :href="login()"
                            class="rounded text-sm font-medium text-foreground/80 transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ t('Log in') }}
                        </Link>
                    </nav>

                    <LocaleToggle />

                    <Button
                        v-if="page.props.auth.user"
                        as-child
                        class="hidden sm:inline-flex"
                    >
                        <Link :href="dashboard()">{{ t('Dashboard') }}</Link>
                    </Button>
                    <Button v-else as-child class="hidden sm:inline-flex">
                        <Link :href="register()">{{ t("Let's talk") }}</Link>
                    </Button>

                    <!-- Compact menu on small screens -->
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
                            <DropdownMenuItem as-child>
                                <Link
                                    :href="
                                        page.props.auth.user
                                            ? dashboard()
                                            : register()
                                    "
                                >
                                    {{
                                        page.props.auth.user
                                            ? t('Dashboard')
                                            : t("Let's talk")
                                    }}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="mt-8">
            <div
                class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-5 py-10 sm:flex-row sm:items-center sm:justify-between sm:px-8"
            >
                <AppLogo />
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Marketing brighter together.') }}
                </p>
                <p class="font-mono text-xs text-muted-foreground">
                    © {{ new Date().getFullYear() }} Alima.
                </p>
            </div>
        </footer>
    </div>
</template>
