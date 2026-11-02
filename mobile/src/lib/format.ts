import { currentLocale } from '@/lib/i18n';

/**
 * Turning the API's numbers and timestamps into something a person reads.
 *
 * Three conventions from the API contract (`docs/api/README.md` §7) are load-bearing:
 *
 *   - **Money is integer minor units** with its currency beside it. Dividing by 100 everywhere is
 *     what eventually produces a £12.3 on someone's dashboard, so it is done once, here, by
 *     `Intl.NumberFormat` which knows that JPY has no minor unit and KWD has three.
 *   - **Timestamps are UTC ISO-8601**; the team's own clock is in `team.timezone`. A member in
 *     Dubai looking at a lead sourced at 23:30 UTC should see tomorrow's date, which is what the
 *     timezone argument is for — it is not cosmetic.
 *   - **Enums travel as a stable key with a `*_label` beside them.** The label is an English
 *     translation key, so it goes through `t()` — never printed raw.
 */

/** Money, from minor units. `currency` may be null on a figure that is only ever a count. */
export function formatMoney(
    minorUnits: number,
    currency: string | null,
): string {
    const code = currency ?? 'GBP';

    try {
        return new Intl.NumberFormat(currentLocale.value, {
            style: 'currency',
            currency: code,
            // The currency's own precision, with the decimals dropped when there are none to
            // show: £1,234 on a tile rather than £1,234.00, but 1.500 KWD still 1.500 rather
            // than rounded to 2. Pinning `maximumFractionDigits: 0` would have done the latter —
            // which is a wrong number, not a tidier one.
            trailingZeroDisplay: 'stripIfInteger',
        }).format(minorUnits / minorUnitDivisor(code));
    } catch {
        // An unknown currency code from the server should not take a tile down with it.
        return `${code} ${Math.round(minorUnits / 100)}`;
    }
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(currentLocale.value).format(value);
}

/** A signed percentage for a KPI delta: `+12%`, `-4%`, `0%`. */
export function formatDelta(value: number): string {
    const rounded = Math.round(value);
    const sign = rounded > 0 ? '+' : '';

    return `${sign}${formatNumber(rounded)}%`;
}

/** A date, in the team's timezone. Pass the timezone through from `CurrentTeam.timezone`. */
export function formatDate(
    iso: string | null,
    timezone: string | null = null,
): string {
    const date = toDate(iso);

    if (date === null) {
        return '—';
    }

    return new Intl.DateTimeFormat(currentLocale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: timezone ?? undefined,
    }).format(date);
}

export function formatDateTime(
    iso: string | null,
    timezone: string | null = null,
): string {
    const date = toDate(iso);

    if (date === null) {
        return '—';
    }

    return new Intl.DateTimeFormat(currentLocale.value, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone ?? undefined,
    }).format(date);
}

/**
 * "3 days ago", in the member's language.
 *
 * `Intl.RelativeTimeFormat` rather than a hand-rolled ladder, because Arabic has a dual as well
 * as a plural and "2 days ago" is not "يومين" by any rule a template string can express.
 */
export function formatRelative(iso: string | null): string {
    const date = toDate(iso);

    if (date === null) {
        return '—';
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat(currentLocale.value, {
        numeric: 'auto',
    });

    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['week', 604_800],
        ['day', 86_400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(Math.round(seconds), 'second');
}

/** Initials for an avatar, from a name that may be one word or five. */
export function initials(name: string): string {
    const parts = name.trim().split(/\s+/u).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    const first = [...parts[0]][0] ?? '';
    const last = parts.length > 1 ? ([...(parts.at(-1) ?? '')][0] ?? '') : '';

    return (first + last).toUpperCase();
}

function toDate(iso: string | null): Date | null {
    if (iso === null || iso === '') {
        return null;
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * How many minor units make one major unit. Three decimal currencies in the markets Alima serves
 * (ADR 0007: UK and UAE, with the Gulf next door) and the zero-decimal ones, which otherwise turn
 * ¥1,200 into ¥12.
 */
function minorUnitDivisor(currency: string): number {
    const code = currency.toUpperCase();

    if (['JPY', 'KRW', 'VND', 'CLP', 'ISK'].includes(code)) {
        return 1;
    }

    if (['BHD', 'KWD', 'OMR', 'JOD', 'TND'].includes(code)) {
        return 1000;
    }

    return 100;
}
