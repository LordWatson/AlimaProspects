<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { PanelLeft, Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import ImpersonationBanner from '@/components/admin/ImpersonationBanner.vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import LocaleToggle from '@/components/LocaleToggle.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
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
const { t, isRtl, locale } = useI18n();

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

// Breadcrumb titles are declared as English keys in each page's `defineOptions`, so they are
// translated here rather than shipped raw to the topbar — the label beside an Arabic hero should
// not be the only English word on the screen.
const title = computed(() => t(props.breadcrumbs.at(-1)?.title ?? 'Dashboard'));

const page = usePage();

/** The leads search and the "New lead" pill only mean something to a member of a team. */
const hasTeam = computed(() => page.props.auth.currentTeam !== null);

/*
 * The topbar search is the leads list's own `search` filter, not a palette: it submits to the
 * screen that already knows how to search, so there is one search and not two that disagree.
 */
/** `LeadFilters::MAX_SEARCH` — a longer term is refused by the request, so it cannot be typed. */
const MAX_SEARCH = 120;

const currentSearch = (): string =>
    new URL(page.url, 'http://x').searchParams.get('search') ?? '';

// Seeded from the URL, so on `/leads?search=acme` the box says what the list is showing.
const query = ref(currentSearch());
watch(
    () => page.url,
    () => (query.value = currentSearch()),
);

function search(): void {
    const term = query.value.trim();

    if (term === '' && currentSearch() === '') {
        return;
    }

    // Already on the list: keep its other filters (repeated keys included) and change only the
    // search, back to the first page.
    const params = page.url.startsWith('/leads?')
        ? new URL(page.url, 'http://x').searchParams
        : new URLSearchParams();
    params.delete('page');

    if (term === '') {
        params.delete('search');
    } else {
        params.set('search', term);
    }

    const qs = params.toString();
    router.get(qs === '' ? '/leads' : `/leads?${qs}`);
}

const today = computed(() =>
    new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : locale.value, {
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
    <div
        class="flex min-h-svh bg-background text-foreground lg:fixed lg:inset-0 lg:overflow-hidden"
    >
        <!-- Desktop rail: flush, full height, Deep Teal -->
        <aside class="hidden w-[15.5rem] shrink-0 overflow-hidden lg:block">
            <AppSidebar />
        </aside>

        <!-- Content surface -->
        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <!--
              An operator impersonating a customer sees this above everything else, on every screen
              (Phase 19a); platform notices sit under it, because "you are signed in as somebody
              else" outranks "we are doing maintenance on Sunday". Both render nothing on an
              ordinary visit.
            -->
            <ImpersonationBanner />
            <AnnouncementBanner />

            <!-- Topbar -->
            <header
                class="flex h-[3.75rem] shrink-0 items-center gap-3 border-b border-border px-4 sm:px-6"
            >
                <!-- Mobile drawer -->
                <Sheet>
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="lg:hidden"
                            :aria-label="t('Menu')"
                        >
                            <PanelLeft class="size-[18px]" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        :side="isRtl ? 'right' : 'left'"
                        class="w-[15.5rem] border-0 bg-teal p-0 text-cream"
                    >
                        <SheetTitle class="sr-only">{{ t('Menu') }}</SheetTitle>
                        <AppSidebar />
                    </SheetContent>
                </Sheet>
                <span
                    class="h-[18px] w-px shrink-0 bg-border lg:hidden"
                    aria-hidden="true"
                />

                <!--
                  A label, not a heading: every main screen opens with a `PageHero`, and that
                  card's statement is the page's one `<h1>`.
                -->
                <p class="truncate text-sm font-medium">
                    {{ title }}
                </p>

                <div class="ms-auto flex items-center gap-2 sm:gap-2.5">
                    <Button
                        v-if="hasTeam"
                        variant="brand"
                        size="sm"
                        as-child
                        class="max-sm:size-[34px] max-sm:px-0"
                    >
                        <Link href="/leads/create" data-test="topbar-new-lead">
                            <Plus class="size-3.5" />
                            <span class="max-sm:sr-only">{{
                                t('New lead')
                            }}</span>
                        </Link>
                    </Button>

                    <Button
                        v-if="hasTeam"
                        variant="ghost"
                        size="icon-sm"
                        as-child
                        class="border border-border bg-card md:hidden"
                    >
                        <Link href="/leads" :aria-label="t('Search leads')">
                            <Search class="size-4" />
                        </Link>
                    </Button>

                    <form
                        v-if="hasTeam"
                        role="search"
                        class="hidden h-[34px] w-56 items-center gap-2 rounded-full border border-border bg-card ps-3 pe-2 text-sm transition-colors duration-150 focus-within:border-foreground md:flex"
                        @submit.prevent="search"
                    >
                        <Search
                            class="size-[15px] shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <label for="topbar-search" class="sr-only">{{
                            t('Search leads')
                        }}</label>
                        <input
                            id="topbar-search"
                            v-model="query"
                            type="search"
                            :maxlength="MAX_SEARCH"
                            :placeholder="t('Search leads…')"
                            class="min-w-0 flex-1 bg-transparent text-[0.8125rem] outline-none placeholder:text-muted-foreground"
                        />
                    </form>

                    <span
                        class="hidden font-mono text-[0.6875rem] tracking-[0.12em] text-muted-foreground xl:inline rtl:tracking-normal"
                    >
                        {{ today }}
                    </span>
                    <LocaleToggle />
                    <AppearanceToggle />
                    <NotificationBell />
                </div>
            </header>

            <!-- `scroll-region`: this element scrolls, not the window, so Inertia has to be told to
                 send it back to the top on each visit. Without it a new page opens wherever the
                 last one was left. -->
            <main
                scroll-region
                class="flex-1 overflow-y-auto px-4 py-5 sm:px-6"
            >
                <!--
                  Keyed by the page component, not the URL: a new screen fades up, while a filter
                  change on the same screen (a new query string) keeps its state and does not
                  replay the entrance.
                -->
                <div :key="page.component" class="animate-screen">
                    <slot />
                </div>
            </main>
        </div>

        <Toaster />
    </div>
</template>
