<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { PaginationLink } from '@/types';

/*
 * Server-driven pager for any Laravel paginator. Rendered as a <nav> of links so a page is a real
 * URL — shareable, bookmarkable and back-button friendly — rather than client state.
 *
 * Logical properties throughout, and the chevrons are swapped under RTL because "next" points the
 * way the language reads, not the way the icon was drawn.
 */
const props = withDefaults(
    defineProps<{
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
        /** What is being paged, for the nav's accessible name. The component is shared, so the
         *  caller names its own list rather than inheriting "leads". */
        label?: string;
    }>(),
    { label: 'Pages' },
);

const { t, isRtl } = useI18n();
const { number } = useFormat();

// Laravel brackets its links with "&laquo; Previous" / "Next &raquo;"; those get the chevrons, and
// the rest are page numbers (or an ellipsis, which is not a link).
const previous = computed(() => props.links[0] ?? null);
const next = computed(() => props.links[props.links.length - 1] ?? null);
const pages = computed(() => props.links.slice(1, -1));

const hasPages = computed(() => props.meta.last_page > 1);
</script>

<template>
    <nav
        v-if="hasPages"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        :aria-label="t(props.label)"
    >
        <p class="font-mono text-xs text-muted-foreground tabular-nums">
            {{
                t('Showing :from–:to of :total', {
                    from: number(meta.from ?? 0),
                    to: number(meta.to ?? 0),
                    total: number(meta.total),
                })
            }}
        </p>

        <ul class="flex flex-wrap items-center gap-1">
            <li>
                <Link
                    v-if="previous?.url"
                    :href="previous.url"
                    preserve-scroll
                    class="flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:border-brand/40 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('Previous page')"
                >
                    <component
                        :is="isRtl ? ChevronRight : ChevronLeft"
                        class="size-4"
                    />
                </Link>
                <span
                    v-else
                    class="flex size-9 items-center justify-center rounded-full border border-border/60 text-muted-foreground/40"
                    aria-hidden="true"
                >
                    <component
                        :is="isRtl ? ChevronRight : ChevronLeft"
                        class="size-4"
                    />
                </span>
            </li>

            <li v-for="(link, index) in pages" :key="`${link.label}-${index}`">
                <Link
                    v-if="link.url && !link.active"
                    :href="link.url"
                    preserve-scroll
                    class="flex h-9 min-w-9 items-center justify-center rounded-full px-3 font-mono text-xs text-muted-foreground tabular-nums transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('Page :page', { page: link.label })"
                >
                    {{ link.label }}
                </Link>
                <span
                    v-else
                    class="flex h-9 min-w-9 items-center justify-center rounded-full px-3 font-mono text-xs tabular-nums"
                    :class="
                        link.active
                            ? 'bg-brand text-brand-foreground'
                            : 'text-muted-foreground'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ link.label }}
                </span>
            </li>

            <li>
                <Link
                    v-if="next?.url"
                    :href="next.url"
                    preserve-scroll
                    class="flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:border-brand/40 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('Next page')"
                >
                    <component
                        :is="isRtl ? ChevronLeft : ChevronRight"
                        class="size-4"
                    />
                </Link>
                <span
                    v-else
                    class="flex size-9 items-center justify-center rounded-full border border-border/60 text-muted-foreground/40"
                    aria-hidden="true"
                >
                    <component
                        :is="isRtl ? ChevronLeft : ChevronRight"
                        class="size-4"
                    />
                </span>
            </li>
        </ul>
    </nav>
</template>
