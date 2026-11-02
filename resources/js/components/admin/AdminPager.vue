<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import type { AdminPagination } from '@/types';

/*
 * Previous/next paging for the panel's four paginated oversight screens (Phase 19c).
 *
 * Deliberately not a numbered pager. These tables are append-only and ordered newest first, so
 * page 47 of the audit trail is a different set of rows an hour later and a link to it means
 * nothing — what an operator actually does is walk back until they find the entry, or narrow the
 * filters. The total is shown because "how many entries match?" is the useful number.
 *
 * Paging preserves every other query parameter, so stepping through a page does not silently drop
 * the filters that produced it — the bug a bare `?page=2` link would introduce.
 */
const props = defineProps<{ pagination: AdminPagination; label: string }>();

const { number } = useFormat();

const hasPrevious = computed(() => props.pagination.current_page > 1);
const hasNext = computed(
    () => props.pagination.current_page < props.pagination.last_page,
);

function go(page: number): void {
    const query = Object.fromEntries(
        new URLSearchParams(window.location.search).entries(),
    );

    router.get(
        window.location.pathname,
        { ...query, page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <nav
        v-if="pagination.total > 0"
        class="flex flex-wrap items-center justify-between gap-3"
        :aria-label="`${label} pages`"
    >
        <p class="text-sm text-muted-foreground">
            {{ number(pagination.total) }} {{ label }} · page
            {{ number(pagination.current_page) }} of
            {{ number(pagination.last_page) }}
        </p>

        <div class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="!hasPrevious"
                @click="go(pagination.current_page - 1)"
            >
                <ChevronLeft class="size-4" />
                Newer
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="!hasNext"
                @click="go(pagination.current_page + 1)"
            >
                Older
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </nav>
</template>
