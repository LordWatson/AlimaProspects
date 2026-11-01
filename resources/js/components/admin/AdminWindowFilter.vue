<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { AdminWindowFilters } from '@/types';

/*
 * The window every platform screen is read over (Phase 19c).
 *
 * **Its own control rather than the tenant dashboard's `ReportRangeFilter`.** That one is a
 * translated dropdown built around `ReportFilterOptions`, which also carries the tenant report
 * list — and the panel is English-only by decision (`AdminLayout`) and has its own report list. A
 * plain select and two date inputs is the whole requirement here, and bending the tenant control
 * to fit would have coupled an operator screen to a customer's.
 *
 * **Every timestamp in the panel is UTC**, including these bounds: an operator belongs to no team,
 * so there is no team timezone to cut a window in, and reading an impersonated tenant's would be
 * worse than none. The label says so, because a date input that does not say which clock it is on
 * is a date input somebody will be off by a day on.
 *
 * Server-driven, like the registry's index: the window goes in the query string and the server
 * aggregates against it, so a URL an operator pastes into a conversation reproduces exactly what
 * they were looking at and the export link carries the same window. `preserveState` keeps the open
 * tab and the scroll position; `replace` keeps a dozen filter changes out of the back button.
 */
const props = defineProps<{
    filters: AdminWindowFilters;
    ranges: { value: string; label: string }[];
    /** Extra query parameters to carry through a window change — a page's own filters. */
    extra?: Record<string, string | number | null>;
}>();

const range = ref(props.filters.range);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

// The server is the source of truth for what is being shown: after a reload the inputs follow the
// window that came back, not whatever was last typed into them.
watch(
    () => [props.filters.range, props.filters.from, props.filters.to],
    ([nextRange, nextFrom, nextTo]) => {
        range.value = nextRange ?? 'last_30_days';
        from.value = nextFrom ?? '';
        to.value = nextTo ?? '';
    },
);

const isCustom = computed(() => range.value === 'custom');

function apply(): void {
    const query: Record<string, string | number> = { range: range.value };

    if (isCustom.value && from.value !== '' && to.value !== '') {
        query.from = from.value;
        query.to = to.value;
    }

    for (const [key, value] of Object.entries(props.extra ?? {})) {
        if (value !== null && value !== '') {
            query[key] = value;
        }
    }

    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card px-4 py-3"
        @submit.prevent="apply"
    >
        <div class="flex flex-col gap-1">
            <Label
                for="admin-window-range"
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Window
            </Label>
            <NativeSelect
                id="admin-window-range"
                v-model="range"
                class="w-48"
                @change="apply"
            >
                <option
                    v-for="option in ranges"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
                <option value="custom">Custom range</option>
            </NativeSelect>
        </div>

        <template v-if="isCustom">
            <div class="flex flex-col gap-1">
                <Label
                    for="admin-window-from"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    From (UTC)
                </Label>
                <Input
                    id="admin-window-from"
                    v-model="from"
                    type="date"
                    class="w-40"
                />
            </div>

            <div class="flex flex-col gap-1">
                <Label
                    for="admin-window-to"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    To (UTC)
                </Label>
                <Input
                    id="admin-window-to"
                    v-model="to"
                    type="date"
                    class="w-40"
                />
            </div>

            <Button type="submit" variant="outline" size="sm">Apply</Button>
        </template>

        <p class="ms-auto max-w-sm text-xs text-muted-foreground">
            Cut in UTC. An operator belongs to no team, so there is no team
            timezone to read a window in.
        </p>
    </form>
</template>
