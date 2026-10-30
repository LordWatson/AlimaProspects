<script setup lang="ts">
import { computed } from 'vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { ReportColumn, ReportTable } from '@/types';

/*
 * Any report, as a table — the `dataviz` guidance's "a table view exists", promoted from a
 * fallback to a first-class way of reading a report. Four of the reports (ICP performance, outreach,
 * team activity, AI usage) are tables first: they carry seven or eight measures per row, and a chart
 * of eight series is a chart nobody reads.
 *
 * It renders whatever columns the server sent, formatting each by its declared format, so a new
 * column appears here without this component changing. Two rules it enforces on every cell:
 *
 *   - **A null is an em dash, never a zero.** A reply rate over no attempts is not 0%; the funnel's
 *     unavailable steps are not zeroes. The whole module is built on that distinction.
 *   - **Only Alima's own words are translated.** A member's name or a prompt key is data (see the
 *     `translated` flag on a column); running it through `t()` would mangle it.
 *
 * The first column is the row header (`<th scope="row">`), so a screen reader announces "Email —
 * reply rate 12%" rather than reading a wall of numbers with no subject.
 */
const props = defineProps<{ table: ReportTable }>();

const { t } = useI18n();
const { number, currency, percent, date } = useFormat();

const columns = computed(() => props.table.columns);

// The first column is the row header. Computed, not destructured at setup: the table swaps when
// the member changes tab, and a captured value would leave the wrong column as the header.
const headerKey = computed(() => props.table.columns[0]?.key ?? '');

/** Numbers right-align so a column of them can be compared down the page, not read across it. */
const alignOf = (column: ReportColumn): string =>
    column.format === 'count' ||
    column.format === 'money' ||
    column.format === 'percent'
        ? 'text-end tabular-nums'
        : 'text-start';

function cell(column: ReportColumn, value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    switch (column.format) {
        case 'count':
            return number(Number(value));
        case 'money':
            return currency(Number(value), column.currency ?? 'GBP');
        case 'percent':
            return percent(Number(value));
        case 'date':
            return date(String(value));
        default:
            return column.translated ? t(String(value)) : String(value);
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div>
            <h3 class="text-xl font-semibold tracking-tight rtl:font-bold">
                {{ t(table.title) }}
            </h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t(table.caption) }}
            </p>
        </div>

        <p v-if="table.rows.length === 0" class="text-sm text-muted-foreground">
            {{ t(table.empty_message) }}
        </p>

        <!--
          Focusable, because it scrolls. At eight columns this overflows on a phone, and a scroll
          container that only a pointer can reach leaves keyboard users unable to see the last
          columns at all. The region is labelled with the report's own title so a screen reader
          says what is being scrolled.
        -->
        <div
            v-else
            class="overflow-x-auto focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            tabindex="0"
            role="region"
            :aria-label="t(table.title)"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="column in columns"
                            :key="column.key"
                            :class="alignOf(column)"
                        >
                            {{ t(column.label) }}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in table.rows" :key="row.key">
                        <template v-for="column in columns" :key="column.key">
                            <!--
                              A real `<th scope="row">`, not a styled cell: it is what makes a
                              screen reader announce "Email — reply rate 12%" instead of a wall of
                              numbers with no subject. `TableCell` is a `<td>` by definition, so
                              this one is written out rather than configured.
                            -->
                            <th
                                v-if="column.key === headerKey"
                                scope="row"
                                class="px-4 py-3 text-start align-middle font-medium"
                            >
                                {{
                                    cell(column, row.cells[column.key] ?? null)
                                }}
                            </th>
                            <TableCell v-else :class="alignOf(column)">
                                {{
                                    cell(column, row.cells[column.key] ?? null)
                                }}
                            </TableCell>
                        </template>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
