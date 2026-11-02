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

    /**
     * How long ago, in words — "2 hours ago", "yesterday", "last week".
     *
     * For the places where the *distance* is the point rather than the instant: a notification, an
     * activity line, "last run". An absolute timestamp there makes the reader do arithmetic
     * against a clock they have to find first, and in a list of twenty it makes them do it twenty
     * times. Where the exact moment also matters, the screen keeps it — usually as the element's
     * `title`, so it is one hover away rather than gone.
     *
     * `numeric: 'auto'` is what produces "yesterday" instead of "1 day ago"; both are correct, and
     * the first is what somebody would have said.
     */
    function relative(value: string | number | Date): string {
        const seconds = Math.round(
            (new Date(value).getTime() - Date.now()) / 1000,
        );

        const units: [Intl.RelativeTimeFormatUnit, number][] = [
            ['year', 31_536_000],
            ['month', 2_592_000],
            ['week', 604_800],
            ['day', 86_400],
            ['hour', 3_600],
            ['minute', 60],
        ];

        const formatter = new Intl.RelativeTimeFormat(numericLocale.value, {
            numeric: 'auto',
        });

        for (const [unit, size] of units) {
            if (Math.abs(seconds) >= size) {
                return formatter.format(Math.round(seconds / size), unit);
            }
        }

        return formatter.format(Math.round(seconds), 'second');
    }

    /**
     * Which **day** an instant falls on, as `YYYY-MM-DD` in the **team's** timezone — the key a
     * list groups its rows by.
     *
     * It has to be the team's day rather than the browser's: a notification raised at 23:30 in
     * Dubai is still Tuesday's for a Dubai team, and grouping it under Wednesday because the
     * reader is in London would put it in the wrong pile. `en-CA` is used for the formatting only,
     * because it is the locale whose short date already *is* `YYYY-MM-DD`; nothing here is shown
     * to anybody.
     */
    function dayKey(value: string | number | Date): string {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone.value,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(value));
    }

    /**
     * A timestamp in **UTC**, labelled as such.
     *
     * For the admin panel, where `dateTime()` is the wrong answer: it formats in the current team's
     * timezone, and an operator is either in no team at all or — worse — impersonating one, so the
     * same grant end date would read differently depending on whose account they last looked at. A
     * grant window and an announcement window are contractual, and the panel's `datetime-local`
     * inputs are read as UTC by the server, so this is the half that makes the screen agree with it.
     */
    function utcDateTime(value: string | number | Date): string {
        return new Intl.DateTimeFormat(numericLocale.value, {
            timeZone: 'UTC',
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    }

    /**
     * A **date** in UTC, labelled as such — the date-only sibling of {@see utcDateTime}.
     *
     * For the admin panel's platform figures, which are rolled up per UTC day: `date()` would
     * render that day in the current team's timezone and, for an operator who has just been
     * impersonating somebody, in *that customer's*. A rollup day is a day, not an instant, so
     * padding it with a midnight it never had and then shifting that midnight is two mistakes.
     */
    function utcDate(value: string | number | Date): string {
        return new Intl.DateTimeFormat(numericLocale.value, {
            timeZone: 'UTC',
            dateStyle: 'medium',
        }).format(new Date(value));
    }

    return {
        number,
        currency,
        percent,
        date,
        dateTime,
        relative,
        dayKey,
        utcDate,
        utcDateTime,
        timezone,
    };
}
