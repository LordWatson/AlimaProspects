import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';

/**
 * Locale- and timezone-aware formatting via the Intl API. Numbers and dates are
 * formatted in the active UI locale; dates and times are displayed in the active
 * team's timezone (stored UTC in the DB, per CLAUDE.md §2). Arabic uses Western
 * (Latin) digits for consistency with the product's numeric data.
 */
export function useFormat() {
    const page = usePage();
    const { locale } = useI18n();

    // Force Latin digits even under `ar` so IDs/counts stay legible in tables.
    const numericLocale = computed(() => `${locale.value}-u-nu-latn`);
    const timezone = computed(
        () => page.props.auth.currentTeam?.timezone ?? 'UTC',
    );

    function number(
        value: number,
        options: Intl.NumberFormatOptions = {},
    ): string {
        return new Intl.NumberFormat(numericLocale.value, options).format(
            value,
        );
    }

    function currency(minorUnits: number, code = 'GBP'): string {
        return new Intl.NumberFormat(numericLocale.value, {
            style: 'currency',
            currency: code,
        }).format(minorUnits / 100);
    }

    function percent(value: number, fractionDigits = 0): string {
        return new Intl.NumberFormat(numericLocale.value, {
            style: 'percent',
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        }).format(value);
    }

    function date(
        value: string | number | Date,
        options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' },
    ): string {
        return new Intl.DateTimeFormat(numericLocale.value, {
            timeZone: timezone.value,
            ...options,
        }).format(new Date(value));
    }

    function dateTime(value: string | number | Date): string {
        return date(value, { dateStyle: 'medium', timeStyle: 'short' });
    }

    return { number, currency, percent, date, dateTime, timezone };
}
