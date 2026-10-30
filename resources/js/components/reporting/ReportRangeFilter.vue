<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarRange, Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    ReportFilterOptions,
    ReportFilterState,
    ReportRange,
} from '@/types';

/*
 * The dashboard's date filter — one control, one row, above everything it scopes (the `dataviz`
 * guidance: filters never live inside a chart card, and every figure below re-renders against the
 * same slice so the numbers always agree).
 *
 * Presets as **rows**, not a calendar grid: nobody fights a month view for "last 30 days". The
 * active one carries a tick as well as its highlight, so selection is never colour-alone (WCAG AA,
 * and the brand's rule that Signal Orange is decorative). The custom pair sits behind a hairline in
 * the footer, where it is reachable without being in the way.
 *
 * Filtering is **server-driven**: the window goes in the query string and the server aggregates
 * against it, so a shared URL reproduces what the sender saw and the export link carries the same
 * window. `preserveState` keeps the open tab and scroll position across the reload; `replace` keeps
 * a dozen filter changes out of the back button.
 */
const props = defineProps<{
    filters: ReportFilterState;
    options: ReportFilterOptions;
    /** The window actually being shown, as ISO dates; null for all time. */
    from: string | null;
    to: string | null;
}>();

const { t } = useI18n();
const { date } = useFormat();

const open = ref(false);
const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);

// The server is the source of truth for what is being shown: after a reload the inputs follow the
// window that came back, not whatever was last typed into them.
watch(
    () => [props.filters.from, props.filters.to],
    ([from, to]) => {
        customFrom.value = from;
        customTo.value = to;
    },
);

const activeLabel = computed(() => {
    if (props.filters.range === 'custom') {
        // Translated rather than assembled: an Arabic screen needs its own word for "to" and its
        // own order, which a hard-coded en dash cannot give it (ADR 0007).
        return props.from !== null && props.to !== null
            ? t(':from to :to', { from: date(props.from), to: date(props.to) })
            : t('Custom range');
    }

    const option = props.options.ranges.find(
        (candidate) => candidate.value === props.filters.range,
    );

    return t(option?.label ?? 'All time');
});

function apply(params: Record<string, string>): void {
    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function choose(range: ReportRange): void {
    open.value = false;

    apply(range === 'all_time' ? {} : { range });
}

/**
 * Keep the menu's typeahead off the date inputs without taking its escape hatches away.
 *
 * A bare `@keydown.stop` would do the first and also the second: Escape would no longer close the
 * menu and Tab would no longer move focus out of it, leaving a member stuck in a date field with no
 * keyboard way out. Only the keys that would be read as a search through the presets are stopped.
 */
function onCustomKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' || event.key === 'Tab') {
        return;
    }

    event.stopPropagation();
}

function applyCustom(): void {
    if (customFrom.value === '' || customTo.value === '') {
        return;
    }

    open.value = false;

    apply({
        range: 'custom',
        from: customFrom.value,
        to: customTo.value,
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <DropdownMenu v-model:open="open">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    class="gap-2"
                    data-test="report-range-trigger"
                >
                    <CalendarRange class="size-4" aria-hidden="true" />
                    <span
                        class="font-mono text-xs tracking-eyebrow uppercase rtl:tracking-normal"
                    >
                        {{ t('Period') }}
                    </span>
                    <span>{{ activeLabel }}</span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" class="min-w-64">
                <DropdownMenuLabel class="text-xs text-muted-foreground">
                    {{ t('Show figures for') }}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                <!--
                  `aria-current` is what tells a screen reader which window is showing. The tick
                  beside it is the sighted equivalent and carries no information of its own, so it
                  stays `aria-hidden` — but it does mean the selection is never colour-alone.
                -->
                <DropdownMenuItem
                    v-for="option in options.ranges"
                    :key="option.value"
                    class="gap-2"
                    :aria-current="
                        option.value === filters.range ? 'true' : undefined
                    "
                    :data-test="`report-range-${option.value}`"
                    @select="choose(option.value)"
                >
                    <span
                        class="flex-1"
                        :class="
                            option.value === filters.range
                                ? 'font-semibold'
                                : ''
                        "
                        >{{ t(option.label) }}</span
                    >
                    <Check
                        v-if="option.value === filters.range"
                        class="size-4 text-brand"
                        aria-hidden="true"
                    />
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                <!--
                  The custom pair, behind a hairline in the footer: reachable without being in the
                  way. It is a plain `div` rather than a menu item, and `@keydown.stop` keeps the
                  menu's own typeahead off these inputs — otherwise typing a date would be read as
                  a search through the presets and close the menu on the first keystroke.
                -->
                <div class="flex flex-col gap-2 p-2" @keydown="onCustomKeydown">
                    <div class="grid grid-cols-2 gap-2">
                        <div class="flex flex-col gap-1">
                            <Label
                                for="report-from"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('From') }}
                            </Label>
                            <Input
                                id="report-from"
                                v-model="customFrom"
                                type="date"
                                :max="customTo"
                                data-test="report-range-from"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <Label
                                for="report-to"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('To') }}
                            </Label>
                            <Input
                                id="report-to"
                                v-model="customTo"
                                type="date"
                                :min="customFrom"
                                data-test="report-range-to"
                            />
                        </div>
                    </div>
                    <Button
                        variant="secondary"
                        size="sm"
                        data-test="report-range-apply"
                        @click="applyCustom"
                    >
                        {{ t('Apply dates') }}
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>

        <p v-if="from && to" class="text-sm text-muted-foreground">
            {{ t(':from to :to', { from: date(from), to: date(to) }) }}
        </p>
        <p v-else class="text-sm text-muted-foreground">
            {{ t('Everything since you joined') }}
        </p>
    </div>
</template>
