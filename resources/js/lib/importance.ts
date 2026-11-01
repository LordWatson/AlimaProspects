/**
 * What a criterion's weight means, in words.
 *
 * A number out of 100 tells a member nothing on its own — is 50 a lot? — so both screens that show
 * a weight also show the band it falls in. The thresholds live here rather than in each screen
 * because the editor and the profile list have to agree: a signal a member set as "Important"
 * while editing must not read as "Essential" on the card afterwards.
 *
 * Returns a **translation key**, not a finished string; the caller runs it through `t()`.
 */
export function importanceKey(weight: number | null): string | null {
    if (weight === null) return null;
    if (weight >= 67) return 'Essential';
    if (weight >= 34) return 'Important';

    return 'Nice to have';
}
