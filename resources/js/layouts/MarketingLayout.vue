<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import { useI18n } from '@/composables/useI18n';
import { dashboard, home, login, register } from '@/routes';

/*
 * Public shell, after the Alima Planner reference: a sticky, blurred Cloud Cream header with the
 * lock-up and its mono product tag, centred anchors, and one dark pill CTA ("Let's talk"); an Ink
 * footer under a spectrum rule. Fully bilingual (EN/AR + RTL) via logical properties; the logo
 * never mirrors.
 *
 * **`bare` drops the selling.** The compliance pages that share this shell — the privacy notice and
 * the unsubscribe page — are read by somebody who has just been cold-emailed, and "Let's talk"
 * beside an opt-out button is both a second primary CTA on one view (brand-tokens.md §2.3) and the
 * wrong thing to say. Bare keeps the logo, the language switch and the legal line, and nothing
 * that asks for anything.
 */
const { bare = false } = defineProps<{ bare?: boolean }>();

const { t } = useI18n();
const page = usePage();

/*
 * Anchors on the landing page, the About page beside them. From any other page an anchor would
 * scroll nowhere, so they are absolute (`/#how`) and arrive at the landing page's section.
 */
const nav = [
    { label: 'How it works', href: '/#how' },
    { label: 'Features', href: '/#features' },
    { label: 'Questions', href: '/#faq' },
    { label: 'About', href: '/about' },
];

const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);

/** Esc closes the menu and hands focus back to the button that opened it. */
function closeMenu(): void {
    if (!menuOpen.value) {
        return;
    }

    menuOpen.value = false;
    menuButton.value?.focus();
}
</script>

<template>
    <div
        class="flex min-h-svh flex-col bg-background text-foreground"
        @keydown.esc="closeMenu"
    >
        <header
            class="sticky top-0 z-40 border-b border-ink/8 bg-background/92 backdrop-blur-[14px] dark:border-border"
        >
            <div
                class="mx-auto flex h-16 w-full max-w-[80rem] items-center justify-between gap-6 px-5 sm:px-[clamp(1.25rem,5.5vw,5rem)] md:h-20"
            >
                <Link
                    :href="home()"
                    class="flex shrink-0 items-center gap-4 rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <AppLogo />
                    <span
                        class="hidden font-mono text-xs font-medium tracking-eyebrow text-teal uppercase sm:inline rtl:tracking-normal dark:text-brand"
                        >Prospects</span
                    >
                </Link>

                <nav
                    v-if="!bare"
                    class="hidden items-center gap-9 lg:flex"
                    :aria-label="t('Main menu')"
                >
                    <a
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="rounded text-base text-foreground transition-colors duration-150 hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ t(item.label) }}
                    </a>
                </nav>

                <div class="flex items-center gap-2">
                    <LocaleToggle />

                    <template v-if="!bare">
                        <Link
                            v-if="!page.props.auth.user"
                            :href="login()"
                            class="hidden min-h-12 items-center rounded-full px-3.5 text-base font-semibold text-foreground transition-colors duration-150 hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none md:inline-flex"
                        >
                            {{ t('Log in') }}
                        </Link>
                        <Link
                            :href="
                                page.props.auth.user ? dashboard() : register()
                            "
                            class="hidden min-h-12 items-center rounded-full bg-primary px-6 text-base font-semibold text-primary-foreground transition-colors duration-150 hover:bg-ink-hover focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:inline-flex dark:hover:bg-primary/90"
                        >
                            {{
                                page.props.auth.user
                                    ? t('Dashboard')
                                    : t("Let's talk")
                            }}
                        </Link>

                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-full bg-primary text-primary-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none lg:hidden"
                            :aria-label="menuOpen ? t('Close menu') : t('Menu')"
                            :aria-expanded="menuOpen"
                            aria-controls="marketing-menu"
                            ref="menuButton"
                            @click="menuOpen = !menuOpen"
                        >
                            <X v-if="menuOpen" class="size-5" />
                            <Menu v-else class="size-5" />
                        </button>
                    </template>
                </div>
            </div>

            <!-- The small-screen menu: the anchors set large, then the two actions. -->
            <nav
                v-if="!bare"
                v-show="menuOpen"
                id="marketing-menu"
                class="flex animate-screen flex-col border-t border-border px-5 pt-2 pb-6 lg:hidden"
                :aria-label="t('Menu')"
            >
                <a
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="border-b border-border py-3 text-[1.75rem] font-bold tracking-[-0.03em] text-foreground rtl:tracking-normal"
                    @click="menuOpen = false"
                >
                    {{ t(item.label) }}
                </a>
                <div class="mt-5 flex gap-2">
                    <Link
                        v-if="!page.props.auth.user"
                        :href="login()"
                        class="flex min-h-12 flex-1 items-center justify-center rounded-full border-2 border-foreground text-base font-semibold"
                    >
                        {{ t('Log in') }}
                    </Link>
                    <Link
                        :href="page.props.auth.user ? dashboard() : register()"
                        class="flex min-h-12 flex-1 items-center justify-center rounded-full bg-primary text-base font-semibold text-primary-foreground"
                    >
                        {{
                            page.props.auth.user
                                ? t('Dashboard')
                                : t("Let's talk")
                        }}
                    </Link>
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="bg-ink text-cream">
            <div class="h-1.5 bg-spectrum" aria-hidden="true" />
            <div
                class="mx-auto flex w-full max-w-[80rem] flex-col gap-10 px-5 pt-13 pb-8 sm:px-[clamp(1.25rem,5.5vw,5rem)]"
            >
                <div class="flex flex-wrap justify-between gap-10">
                    <div
                        class="flex max-w-[22.5rem] flex-[1_1_16rem] flex-col gap-4"
                    >
                        <AppLogo tone="dark" />
                        <!-- The tagline is a marketing line, so it stays off the bare pages. -->
                        <p v-if="!bare" class="text-[0.9375rem] text-ink-20">
                            {{
                                t(
                                    'Find, qualify and reach the businesses most likely to buy — in one calm workspace.',
                                )
                            }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap gap-[clamp(2rem,6vw,5.5rem)] text-[0.9375rem]"
                    >
                        <div v-if="!bare" class="flex flex-col gap-2.5">
                            <p
                                class="font-mono text-xs tracking-eyebrow text-ink-40 uppercase rtl:tracking-normal"
                            >
                                {{ t('Product') }}
                            </p>
                            <a
                                href="/#how"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('How it works') }}</a
                            >
                            <a
                                href="/#features"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('Features') }}</a
                            >
                            <a
                                href="/#faq"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('Questions') }}</a
                            >
                        </div>
                        <div class="flex flex-col gap-2.5">
                            <p
                                class="font-mono text-xs tracking-eyebrow text-ink-40 uppercase rtl:tracking-normal"
                            >
                                Alima
                            </p>
                            <Link
                                v-if="!bare"
                                href="/about"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('About') }}</Link
                            >
                            <a
                                href="/privacy"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('Privacy notice') }}</a
                            >
                            <Link
                                v-if="!bare && !page.props.auth.user"
                                :href="login()"
                                class="text-cream transition-colors hover:text-lime"
                                >{{ t('Log in') }}</Link
                            >
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-wrap justify-between gap-3 border-t border-ink-80 pt-5 font-mono text-xs tracking-eyebrow text-ink-40 uppercase rtl:tracking-normal"
                >
                    <span v-if="!bare">{{
                        t('Bold ideas. Real impact.')
                    }}</span>
                    <span>© {{ new Date().getFullYear() }} Alima</span>
                </div>
            </div>
        </footer>
    </div>
</template>
