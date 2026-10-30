/**
 * Bidi isolation for values interpolated into a translated sentence (ADR 0007).
 *
 * A number, a time or a timezone name is a left-to-right run, and dropping one into an Arabic
 * sentence lets the Unicode bidi algorithm reorder the punctuation around it: "تُحذف بعد: 7" can
 * render with the colon on the wrong side, and "Asia/Dubai" inside Arabic can come out with the
 * slash misplaced. The fix is to isolate the run.
 *
 * `t()` returns a plain string that goes into a text node, so there is no element to put `dir` on
 * and `<bdi>` is not available — which is why these are the **characters** rather than markup:
 * U+2068 FIRST STRONG ISOLATE and U+2069 POP DIRECTIONAL ISOLATE do the same job inline, and are
 * invisible and inert in a left-to-right sentence, so an English string is unaffected.
 *
 * Use it for every value whose own direction differs from the sentence it lands in: counts, dates,
 * times, timezone identifiers, versions, URLs. Not for a company name — those get `dir="auto"` on
 * the element that shows them, because a name's own direction is the one that should win.
 */
export function isolate(value: string | number): string {
    return `⁨${value}⁩`;
}
