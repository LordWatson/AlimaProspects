import { describe, expect, it } from 'vitest';
import { formatDate, formatDelta, formatMoney, initials } from '@/lib/format';

/*
 * The formatting layer, which is where the API's three conventions either hold or quietly break
 * (`docs/api/README.md` §7). Each case below is one of them.
 */

describe('money', () => {
    it('reads minor units as minor units', () => {
        // £1,234.00 arrives as 123400. The bug this guards against prints £123,400.
        expect(formatMoney(123_400, 'GBP')).toContain('1,234');
    });

    it('drops the decimals when there are none to show', () => {
        // A tile reads £1,234, not £1,234.00 — but see KWD below: the decimals are dropped
        // only when they are zero, never rounded away.
        expect(formatMoney(123_400, 'GBP')).not.toContain('.00');
    });

    it('knows the currencies that have no minor unit', () => {
        // ¥1,200 arrives as 1200, not 120000 — dividing by 100 would print ¥12.
        expect(formatMoney(1200, 'JPY')).toContain('1,200');
    });

    it('knows the three-decimal currencies of the Gulf', () => {
        // Alima sells into the UAE (ADR 0007), and its neighbours use mils: 1.500 KWD is 1500
        // of them. Rounding that to a whole dinar would be a different number, not a tidier one.
        expect(formatMoney(1500, 'KWD')).toContain('1.5');
    });

    it('survives a currency code it has never heard of', () => {
        // A tile is not worth taking a dashboard down for.
        expect(() => formatMoney(100, 'XYZ')).not.toThrow();
    });
});

describe('dates', () => {
    it('renders a date in the team timezone, not the device one', () => {
        // 23:30 UTC on the 5th is already the 6th in Dubai. A member there must see their own
        // date, which is the entire reason the timezone is carried on the team.
        const utcLate = '2026-03-05T23:30:00Z';

        expect(formatDate(utcLate, 'Asia/Dubai')).toContain('6');
        expect(formatDate(utcLate, 'UTC')).toContain('5');
    });

    it('prints an em dash for a null, never a date', () => {
        expect(formatDate(null)).toBe('—');
        expect(formatDate('not-a-date')).toBe('—');
    });
});

describe('deltas', () => {
    it('signs a rise and leaves a fall to the minus', () => {
        expect(formatDelta(12.4)).toBe('+12%');
        expect(formatDelta(-4)).toBe('-4%');
        expect(formatDelta(0)).toBe('0%');
    });
});

describe('initials', () => {
    it('takes the first and last word', () => {
        expect(initials('Nadia Al Mansouri')).toBe('NM');
        expect(initials('Dana')).toBe('D');
    });

    it('does not split a surrogate pair down the middle', () => {
        // `name[0]` on an emoji or an astral character yields half a code point and renders as a
        // replacement glyph.
        expect(initials('😀 Smith')).toBe('😀S');
    });

    it('has an answer for an empty name', () => {
        expect(initials('   ')).toBe('?');
    });
});
