<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/composables/useFormat';
import type { AdminCellValue, AdminColumn } from '@/types';

/*
 * One cell of a registry table or record page (Phase 19a).
 *
 * The value crosses the wire raw — an integer, a fraction, an ISO instant — and is formatted here,
 * for the reason Reporting gives about its own columns: only the client knows the viewer's locale.
 * In the panel's case the timezone is always UTC, because an operator belongs to no team and
 * `useFormat` falls back to UTC when there is none. That is correct rather than incidental: an
 * operations tool that rendered one tenant's timestamps in another's zone would be worse than one
 * showing raw strings.
 *
 * Booleans get a badge, because a bare `true` in a table of forty columns is unreadable — and the
 * badge uses `status.*` tokens, never Signal Orange, which is decorative only (§2a).
 *
 * A `cost_micros` column arrives as `count`, not `money`, on purpose: micro-units are not minor
 * units, and a currency symbol in front of one would show a figure a thousand times too small.
 */
const props = defineProps<{
    column: AdminColumn;
    value: AdminCellValue;
    /** The row, for a `money` column that needs its sibling currency code. */
    row?: Record<string, AdminCellValue>;
}>();

const { number, currency, percent, dateTime } = useFormat();

const isEmpty = computed(
    () =>
        props.value === null || props.value === '' || props.value === undefined,
);

const isBoolean = computed(() => typeof props.value === 'boolean');

const text = computed<string>(() => {
    const value = props.value;

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    switch (props.column.format) {
        case 'count':
            return typeof value === 'number' ? number(value) : String(value);
        case 'percent':
            return typeof value === 'number'
                ? percent(value, 1)
                : String(value);
        case 'date':
            return dateTime(String(value));
        case 'money': {
            const code = props.column.currency_field
                ? props.row?.[props.column.currency_field]
                : null;

            return typeof value === 'number'
                ? currency(
                      value,
                      typeof code === 'string' ? code.toUpperCase() : 'GBP',
                  )
                : String(value);
        }
        default:
            return String(value);
    }
});
</script>

<template>
    <Badge
        v-if="isBoolean"
        :variant="value ? 'success' : 'secondary'"
        class="font-mono"
    >
        {{ value ? 'Yes' : 'No' }}
    </Badge>
    <span v-else-if="isEmpty" class="text-muted-foreground/60">—</span>
    <span
        v-else
        :class="
            column.numeric
                ? 'font-mono text-xs tabular-nums'
                : 'break-words whitespace-pre-wrap'
        "
    >
        {{ text }}
    </span>
</template>
