<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Megaphone,
    SlidersHorizontal,
    UserPlus,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import type { AdminNavSection } from '@/types';

/*
 * The panel's rail: the logo, the dashboard, then every registry resource grouped by the
 * operator's question rather than by our module boundaries.
 *
 * The nav is **server-driven** — `ResourceRegistry::navigation()` builds it from
 * `config/admin.php`. A hand-maintained copy here would be a second answer to "what can an
 * operator see?", and the day the two disagreed the sidebar would be the one that was wrong.
 *
 * Brand (§2a): Deep Ink rail with Cloud Cream type, DM Mono group labels at eyebrow tracking, pill
 * nav items in Deep Teal when active. No Signal Orange anywhere — this screen has states, and
 * Signal Orange is decorative only.
 *
 * English only, by decision: an internal tool carries no EN/AR obligation, so there is no `t()`
 * here and the strings are plain.
 */
const props = defineProps<{ navigation: AdminNavSection[] }>();

const page = usePage();

const currentKey = computed(() => {
    const match = /\/admin\/resources\/([^/?]+)/.exec(page.url);

    return match?.[1] ?? null;
});

const isDashboard = computed(() => /^\/admin\/?(\?|$)/.test(page.url));

const sections = computed(() => props.navigation);

/**
 * The things an operator *does*, as opposed to the registry below, which is where they go to look
 * something up. Hard-coded rather than server-driven because each one is a bespoke screen with its
 * own ability — there is no list to derive them from, and inventing one would be a second place to
 * keep in step.
 */
const actions = [
    {
        label: 'Onboard a business',
        href: '/admin/provisioning',
        icon: UserPlus,
    },
    { label: 'Announcements', href: '/admin/announcements', icon: Megaphone },
    { label: 'Settings', href: '/admin/settings', icon: SlidersHorizontal },
] as const;
</script>

<template>
    <div class="flex h-full flex-col gap-6 bg-ink px-4 py-6 text-cream">
        <Link
            href="/admin"
            class="flex items-center gap-3 rounded-2xl p-4 focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none"
        >
            <!--
              `tone="dark"` and `size="sm"`, both load-bearing (§4). The default tone is Teal, which
              on an Ink rail is a "Never" in the book and about 2.1:1 besides; and the sizes are
              widths, not tastes — `sm` is 36px tall, which is 121px wide against the hard 120px
              minimum for the horizontal lock-up. A height class passed in here would override that
              and silently break it. `p-4` gives the mark its clear space.
            -->
            <AppLogo tone="dark" size="sm" />
            <span
                class="font-mono text-xs tracking-eyebrow text-cream/70 uppercase"
            >
                Operations
            </span>
        </Link>

        <nav class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto pe-1">
            <Link
                href="/admin"
                class="flex items-center gap-2 rounded-full px-3 py-2 text-sm font-semibold transition-colors focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none"
                :class="
                    isDashboard
                        ? 'bg-teal text-cream'
                        : 'text-cream/80 hover:bg-cream/10 hover:text-cream'
                "
                :aria-current="isDashboard ? 'page' : undefined"
            >
                <LayoutDashboard class="size-4" />
                Dashboard
            </Link>

            <!--
              The three Phase 19b destinations. Above the registry groups because they are things an
              operator *does*, and the registry below is where they go to look something up.
            -->
            <div class="flex flex-col gap-1">
                <p
                    class="px-3 pb-1 font-mono text-xs tracking-eyebrow text-cream/70 uppercase"
                >
                    Operate
                </p>
                <Link
                    v-for="action in actions"
                    :key="action.href"
                    :href="action.href"
                    class="flex items-center gap-2 rounded-full px-3 py-1.5 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none"
                    :class="
                        page.url.startsWith(action.href)
                            ? 'bg-teal font-semibold text-cream'
                            : 'text-cream/75 hover:bg-cream/10 hover:text-cream'
                    "
                    :aria-current="
                        page.url.startsWith(action.href) ? 'page' : undefined
                    "
                >
                    <component :is="action.icon" class="size-4" />
                    {{ action.label }}
                </Link>
            </div>

            <div
                v-for="section in sections"
                :key="section.label"
                class="flex flex-col gap-1"
            >
                <p
                    class="px-3 pb-1 font-mono text-xs tracking-eyebrow text-cream/70 uppercase"
                >
                    {{ section.label }}
                </p>

                <Link
                    v-for="item in section.items"
                    :key="item.key"
                    :href="`/admin/resources/${item.key}`"
                    class="rounded-full px-3 py-1.5 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none"
                    :class="
                        currentKey === item.key
                            ? 'bg-teal font-semibold text-cream'
                            : 'text-cream/75 hover:bg-cream/10 hover:text-cream'
                    "
                    :aria-current="currentKey === item.key ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </div>
        </nav>
    </div>
</template>
