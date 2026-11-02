<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { useAdminNav, type AdminDestination } from '@/composables/useAdminNav';

/*
 * The panel's rail (Phase 19a; rebuilt in the 19d overhaul).
 *
 * **What was wrong with it.** It was fifty-five links in a flat column — eight bespoke screens and
 * forty-four record browsers, every one of them rendered, all the time. Finding "outreach
 * suppressions" meant scrolling a wall and reading. That is the navigation complaint, and no amount
 * of restyling fixes a list that long: the fix is to make the long part *findable* and the short
 * part *prominent*.
 *
 * So the rail now has three parts:
 *
 *  1. **A filter box**, which narrows every group as you type. Three letters gets you there without
 *     leaving the keyboard, and ⌘K opens the full palette from anywhere (`AdminCommandPalette`).
 *  2. **Two short groups that never collapse** — what an operator watches, and what they change.
 *     These are the eight screens somebody opens daily, and they were previously below the fold on
 *     a laptop.
 *  3. **The registry, collapsed by default**, one disclosure per group, with the open ones
 *     remembered in `localStorage`. Forty-four browsers are a reference section, not a menu.
 *
 * Destinations come from `useAdminNav`, shared with the palette: the rail and the palette answering
 * the same question from two hand-maintained lists is how one of them goes stale.
 *
 * Brand: Deep Ink rail, Cloud Cream type, DM Mono group labels at the tracked 12px floor, Deep Teal
 * for the active pill, and a **Cloud Cream focus ring** — the one deliberate deviation on this
 * surface, because `focus.ring` on Ink is technically compliant and practically invisible (§7e.3).
 */
const page = usePage();

const { base, groups } = useAdminNav();

// --- Filtering ---------------------------------------------------------------------------------
const filter = ref('');

function matches(destination: AdminDestination, group: string): boolean {
    const needle = filter.value.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    // The group's name counts, as it does in the palette: "billing" should find what is filed
    // under Billing even when no single row says the word.
    return [destination.label, destination.keywords ?? '', group]
        .join(' ')
        .toLowerCase()
        .includes(needle);
}

/**
 * The rail as rendered: groups with their non-matching destinations removed, and any group left
 * empty removed with them — a group label over nothing reads as a broken page.
 */
const visible = computed(() =>
    groups.value
        .map((group) => ({
            ...group,
            destinations: group.destinations.filter((destination) =>
                matches(destination, group.label),
            ),
        }))
        .filter((group) => group.destinations.length > 0),
);

const isFiltering = computed(() => filter.value.trim() !== '');

// --- Collapsing --------------------------------------------------------------------------------
const STORAGE_KEY = 'alima.admin.rail.open';

/**
 * Which registry groups are open.
 *
 * Remembered per operator, because somebody who works on billing opens the billing group every
 * morning and should not have to say so twice. Wrapped in try/catch: a private window, or blocked
 * site data, must not take the rail down (storage is a convenience, never state).
 */
const openGroups = ref<string[]>(read());

function read(): string[] {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed.filter((k) => typeof k === 'string')
            : [];
    } catch {
        return [];
    }
}

watch(openGroups, (value) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch {
        // No storage, no memory. The rail still works.
    }
});

/**
 * Groups the operator has collapsed **in this visit**, which is what lets them close the group they
 * are standing in. Not persisted: it is an override of "you are here", and it should not outlive
 * being here.
 */
const closed = ref<string[]>([]);

/** A group the operator is standing in is open whether or not they said so. */
const currentGroup = computed(
    () =>
        groups.value.find((group) =>
            group.destinations.some((destination) => isActive(destination)),
        )?.key ?? null,
);

function isOpen(key: string): boolean {
    // Filtering overrides the disclosures: a search that hid its own results behind a closed
    // group would be a search that looked broken.
    if (isFiltering.value) {
        return true;
    }

    // The group an operator is standing in opens itself — but only until they say otherwise.
    // Forcing it open unconditionally made its own button a no-op: it flipped `aria-expanded` and
    // nothing moved, which reads as broken (brand review).
    if (closed.value.includes(key)) {
        return false;
    }

    return openGroups.value.includes(key) || currentGroup.value === key;
}

function toggle(key: string): void {
    if (isOpen(key)) {
        closed.value = [...closed.value, key];
        openGroups.value = openGroups.value.filter(
            (candidate) => candidate !== key,
        );

        return;
    }

    closed.value = closed.value.filter((candidate) => candidate !== key);
    openGroups.value = [...openGroups.value, key];
}

// --- Active state ------------------------------------------------------------------------------
/**
 * Whether this destination is the page.
 *
 * `exact` for the panel's front page: every other URL in the panel starts with it, so a prefix
 * match would light the dashboard on every screen. Everything else is a prefix match, which is
 * what keeps "Queues and jobs" lit on its own sub-pages.
 */
function isActive(destination: AdminDestination): boolean {
    const url = page.url.split('?')[0] ?? '';

    if (destination.match === 'exact') {
        return url === destination.href || url === destination.href + '/';
    }

    return url.startsWith(destination.href);
}
</script>

<template>
    <div class="flex h-full flex-col gap-5 bg-ink px-4 py-6 text-cream">
        <Link
            :href="base"
            class="flex items-center gap-3 rounded-2xl p-4 focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none focus-visible:ring-inset"
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

        <!-- Narrow fifty-five destinations to the one you have in mind. -->
        <div class="relative">
            <Search
                class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-cream/50"
                aria-hidden="true"
            />
            <input
                v-model="filter"
                type="search"
                aria-label="Filter the panel's screens"
                placeholder="Filter screens…"
                class="w-full rounded-full border border-cream/15 bg-cream/5 py-2 ps-9 pe-3 text-sm text-cream transition-colors placeholder:text-cream/60 focus-visible:border-cream/40 focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none focus-visible:ring-inset"
                data-test="rail-filter"
            />
        </div>

        <nav
            class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto pe-1"
            aria-label="Panel screens"
        >
            <p
                v-if="visible.length === 0"
                class="px-3 text-sm text-cream/60"
                data-test="rail-no-matches"
            >
                Nothing matches “{{ filter }}”.
            </p>

            <div
                v-for="group in visible"
                :key="group.key"
                class="flex flex-col gap-1"
            >
                <!-- A collapsible group is a button; a permanent one is a label. The difference is
                     real, so it is carried by the element and not only by an icon. -->
                <button
                    v-if="group.collapsible"
                    type="button"
                    class="flex items-center gap-2 rounded-full px-3 py-1 text-start font-mono text-xs tracking-eyebrow text-cream/70 uppercase transition-colors hover:text-cream focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none focus-visible:ring-inset"
                    :aria-expanded="isOpen(group.key)"
                    :aria-controls="`rail-${group.key}`"
                    @click="toggle(group.key)"
                >
                    <ChevronDown
                        class="duration-fast size-3.5 shrink-0 transition-transform ease-brand"
                        :class="
                            isOpen(group.key) ? '' : '-rotate-90 rtl:rotate-90'
                        "
                        aria-hidden="true"
                    />
                    {{ group.label }}
                    <span class="font-sans text-cream/40 tabular-nums">{{
                        group.destinations.length
                    }}</span>
                </button>
                <p
                    v-else
                    class="px-3 pb-1 font-mono text-xs tracking-eyebrow text-cream/70 uppercase"
                >
                    {{ group.label }}
                </p>

                <div
                    v-show="isOpen(group.key)"
                    :id="`rail-${group.key}`"
                    class="flex flex-col gap-1"
                >
                    <Link
                        v-for="destination in group.destinations"
                        :key="destination.href"
                        :href="destination.href"
                        class="duration-fast flex items-center gap-2 rounded-full px-3 py-1.5 text-sm transition-colors ease-brand focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none focus-visible:ring-inset"
                        :class="
                            isActive(destination)
                                ? 'bg-teal font-semibold text-cream'
                                : 'text-cream/75 hover:bg-cream/10 hover:text-cream'
                        "
                        :aria-current="
                            isActive(destination) ? 'page' : undefined
                        "
                    >
                        <component
                            :is="destination.icon"
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ destination.label }}</span>
                    </Link>
                </div>
            </div>
        </nav>
    </div>
</template>
